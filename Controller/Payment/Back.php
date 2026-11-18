<?php
declare(strict_types=1);

namespace PaySuite\Gateway\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Model\Order;

/**
 * GET /paysuite/payment/back  (return_url da PaySuite)
 */
class Back implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly OrderManagementInterface $orderManagement,
        private readonly ManagerInterface $messageManager
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();
        $order    = $this->checkoutSession->getLastRealOrder();

        if (!$order->getId()) {
            return $redirect->setPath('/');
        }

        $status   = strtolower((string) $this->request->getParam('status', ''));
        $failed   = in_array($status, ['failed', 'cancelled', 'canceled', 'error'], true);
        $canceled = $order->getState() === Order::STATE_CANCELED;

        if ($failed || $canceled) {
            $this->checkoutSession->restoreQuote();
            $this->messageManager->addErrorMessage(__('O pagamento não foi concluído. Pode tentar novamente.'));
            return $redirect->setPath('checkout/cart');
        }

        return $redirect->setPath('checkout/onepage/success');
    }
}
