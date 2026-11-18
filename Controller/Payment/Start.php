<?php
declare(strict_types=1);

namespace PaySuite\Gateway\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PaySuite\Gateway\Model\Api\Client;
use PaySuite\Gateway\Model\Api\ContactResolver;
use PaySuite\Gateway\Model\Config;
use PaySuite\Gateway\Model\DescriptionBuilder;
use PaySuite\Gateway\Model\WebhookUrlProvider;
use Psr\Log\LoggerInterface;

/**
 * GET /paysuite/payment/start
 * Cria o pagamento na PaySuite para a última encomenda e redirecciona o cliente.
 */
class Start implements HttpGetActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly Client $client,
        private readonly ContactResolver $contactResolver,
        private readonly WebhookUrlProvider $webhookUrlProvider,
        private readonly DescriptionBuilder $descriptionBuilder,
        private readonly UrlInterface $urlBuilder,
        private readonly ManagerInterface $messageManager,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderManagementInterface $orderManagement,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order->getId() || !$order->getPayment()
            || $order->getPayment()->getMethod() !== Config::METHOD_CODE) {
            return $redirect->setPath('checkout/cart');
        }

        try {
            $storeId = (int) $order->getStoreId();

            $payload = [
                'amount'       => number_format((float) $order->getGrandTotal(), 2, '.', ''),
                'reference'    => $order->getIncrementId(),
                'description'  => $this->descriptionBuilder->build($order),
                'contact_id'   => $this->contactResolver->resolveId($order),
                'webhook_url'  => $this->webhookUrlProvider->get($storeId),
                'callback_url' => $this->webhookUrlProvider->get($storeId),
                'return_url'   => $this->getReturnUrl($storeId),
            ];

            $response    = $this->client->request('POST', 'payments', [], $payload, $storeId);
            $checkoutUrl = (string) ($response['data']['checkout_url'] ?? $response['checkout_url'] ?? '');

            if ($checkoutUrl === '') {
                throw new LocalizedException(__('PaySuite não devolveu o link de pagamento.'));
            }

            $paymentId = (string) ($response['data']['id'] ?? '');
            if ($paymentId !== '') {
                $order->getPayment()->setAdditionalInformation('paysuite_payment_id', $paymentId);
            }
            $order->addCommentToStatusHistory(sprintf('PaySuite: pagamento %s criado. Cliente redireccionado.', $paymentId ?: '?'));
            $this->orderRepository->save($order);

            return $redirect->setUrl($checkoutUrl);
        } catch (\Throwable $e) {
            $this->logger->error('PaySuite start: ' . $e->getMessage(), ['order' => $order->getIncrementId()]);
            $this->checkoutSession->restoreQuote();
            if ($order->canCancel()) {
                $this->orderManagement->cancel((int) $order->getEntityId());
            }
            $this->messageManager->addErrorMessage(
                __('Não foi possível iniciar o pagamento PaySuite. Tente novamente.')
            );
            return $redirect->setPath('checkout/cart');
        }
    }

    private function getReturnUrl(int $storeId): string
    {
        $override = $this->config->getWebhookBaseUrl($storeId);
        if ($override !== '') {
            return rtrim($override, '/') . '/paysuite/payment/back';
        }
        return $this->urlBuilder->getUrl('paysuite/payment/back');
    }
}
