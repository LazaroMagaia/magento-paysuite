<?php
declare(strict_types=1);

namespace PaySuite\Gateway\Model;

use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * URL do webhook calculado automaticamente a partir do URL da loja (sempre HTTPS).
 * Enviar como "webhook_url" ao criar cada pagamento.
 */
class WebhookUrlProvider
{
    private const ROUTE = 'paysuite/webhook';

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config
    ) {
    }

    public function get(?int $storeId = null): string
    {
        $override = $this->config->getWebhookBaseUrl($storeId);
        if ($override !== '') {
            return rtrim($override, '/') . '/' . self::ROUTE;
        }

        $base = $this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_LINK, true);

        return rtrim($base, '/') . '/' . self::ROUTE;
    }
}
