<?php
declare(strict_types=1);

namespace PaySuite\Gateway\Model;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Descrição automática do pagamento: "Encomenda #000000001 - Nome da Loja".
 */
class DescriptionBuilder
{
    public function __construct(private readonly StoreManagerInterface $storeManager)
    {
    }

    public function build(OrderInterface $order): string
    {
        $storeName = $this->storeManager->getStore((int) $order->getStoreId())->getName();

        return mb_substr(sprintf('Encomenda #%s - %s', $order->getIncrementId(), $storeName), 0, 255);
    }
}
