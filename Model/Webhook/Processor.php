<?php
declare(strict_types=1);

namespace PaySuite\Gateway\Model\Webhook;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use PaySuite\Gateway\Model\Config;
use Psr\Log\LoggerInterface;

class Processor
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws NoSuchEntityException quando a encomenda de um pagamento não existe (a PaySuite fará retry)
     */
    public function process(string $event, array $data): void
    {
        match ($event) {
            'payment.success' => $this->onPaymentSuccess($data),
            'payment.failed'  => $this->onPaymentFailed($data),
            'refund.success', 'refund.failed' => $this->onRefund($event, $data),
            'payout.success', 'payout.failed' => $this->debug('Payout recebido (sem acção na loja)', $data),
            default => $this->debug('Evento desconhecido ignorado: ' . $event, $data),
        };
    }

    private function onPaymentSuccess(array $data): void
    {
        $order = $this->loadOrderByReference($data);
        if (!$this->isPaySuiteOrder($order)) {
            return;
        }

        if ($order->hasInvoices() || in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE], true)) {
            $this->debug('Encomenda já paga, ignorado', $data);
            return;
        }

        if (isset($data['amount']) && abs((float) $data['amount'] - (float) $order->getGrandTotal()) > 0.01) {
            $order->addCommentToStatusHistory(
                sprintf('PaySuite: valor divergente no pagamento %s (recebido %s, esperado %s). Não capturado.',
                    $data['id'], $data['amount'], $order->getGrandTotal())
            );
            $this->orderRepository->save($order);
            $this->logger->warning('PaySuite: amount mismatch', ['order' => $order->getIncrementId(), 'data' => $data]);
            return;
        }

        // O dinheiro já saiu da conta do cliente: um "success" tem sempre prioridade sobre um cancelamento.
        if ($order->getState() === Order::STATE_CANCELED) {
            $this->reopenCanceledOrder($order);
            $order->addCommentToStatusHistory(
                sprintf('PaySuite: pagamento %s confirmado depois de a encomenda ser cancelada. Encomenda reaberta.', $data['id'])
            );
        }

        $payment = $order->getPayment();
        $payment->setTransactionId((string) $data['id']);
        $payment->setIsTransactionClosed(true);
        $payment->registerCaptureNotification((float) $order->getBaseGrandTotal());
        $order->addCommentToStatusHistory(sprintf('PaySuite: pagamento %s confirmado.', $data['id']));
        $this->orderRepository->save($order);
    }

    private function onPaymentFailed(array $data): void
    {
        $order = $this->loadOrderByReference($data);
        if (!$this->isPaySuiteOrder($order)) {
            return;
        }

        if ($order->hasInvoices() || in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE], true)) {
            $this->debug('Encomenda já paga, "failed" ignorado', $data);
            return;
        }

        // Não cancela: a PaySuite pode enviar um "success" depois de uma tentativa falhada.
        $order->addCommentToStatusHistory(
            sprintf('PaySuite: tentativa de pagamento %s falhou. A encomenda mantém-se pendente.', $data['id'])
        );
        $this->orderRepository->save($order);
    }

    private function onRefund(string $event, array $data): void
    {
        $order = $this->loadOrderByPaymentId((string) ($data['payment_id'] ?? ''));
        if ($order === null) {
            $this->logger->warning('PaySuite: encomenda do refund não encontrada', $data);
            return;
        }

        $order->addCommentToStatusHistory(sprintf(
            'PaySuite: refund %s (%s) %s — valor %s. Crie o Credit Memo manualmente se aplicável.',
            $data['reference'] ?? $data['id'],
            $data['id'],
            $event === 'refund.success' ? 'concluído' : 'falhou',
            $data['amount'] ?? '?'
        ));
        $this->orderRepository->save($order);
    }

    /**
     * Repõe uma encomenda cancelada em "Pending" para que o pagamento possa ser capturado.
     * Nota: o stock não é reservado de novo.
     */
    private function reopenCanceledOrder(Order $order): void
    {
        foreach ($order->getAllItems() as $item) {
            $item->setQtyCanceled(0);
            $item->setTaxCanceled(0);
            $item->setDiscountTaxCompensationCanceled(0);
        }
        foreach ([
            'base_discount_canceled', 'base_shipping_canceled', 'base_subtotal_canceled',
            'base_tax_canceled', 'base_total_canceled', 'discount_canceled',
            'shipping_canceled', 'subtotal_canceled', 'tax_canceled', 'total_canceled',
        ] as $field) {
            $order->setData($field, 0);
        }
        $order->setState(Order::STATE_NEW)->setStatus('pending');
    }

    /** reference = increment_id da encomenda (enviado ao criar o pagamento). */
    private function loadOrderByReference(array $data): Order
    {
        $reference = (string) ($data['reference'] ?? '');
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $reference)
            ->setPageSize(1)
            ->create();
        $items = $this->orderRepository->getList($criteria)->getItems();

        if (!$items) {
            throw new NoSuchEntityException(__('Encomenda "%1" não encontrada.', $reference));
        }
        /** @var Order $order */
        $order = reset($items);
        return $order;
    }

    /** Usa o txn_id gravado em registerCaptureNotification (= payment id da PaySuite). */
    private function loadOrderByPaymentId(string $paymentId): ?Order
    {
        if ($paymentId === '') {
            return null;
        }
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('txn_id', $paymentId)
            ->setPageSize(1)
            ->create();
        $items = $this->transactionRepository->getList($criteria)->getItems();
        if (!$items) {
            return null;
        }
        $transaction = reset($items);
        return $this->orderRepository->get((int) $transaction->getOrderId());
    }

    private function isPaySuiteOrder(Order $order): bool
    {
        $ok = $order->getPayment() && $order->getPayment()->getMethod() === Config::METHOD_CODE;
        if (!$ok) {
            $this->logger->warning('PaySuite: encomenda não usa o método paysuite', ['order' => $order->getIncrementId()]);
        }
        return $ok;
    }

    private function debug(string $message, array $context = []): void
    {
        if ($this->config->isDebug()) {
            $this->logger->info('PaySuite webhook: ' . $message, $context);
        }
    }
}
