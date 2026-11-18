<?php
declare(strict_types=1);

namespace PaySuite\Gateway\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Sales\Model\Order;

/**
 * Mantém a encomenda em "Pending" até o webhook da PaySuite confirmar o pagamento.
 */
class InitializeCommand implements CommandInterface
{
    public function execute(array $commandSubject)
    {
        $stateObject = $commandSubject['stateObject'];
        $stateObject->setData('state', Order::STATE_NEW);
        $stateObject->setData('status', 'pending');
        $stateObject->setData('is_notified', false);

        return null;
    }
}
