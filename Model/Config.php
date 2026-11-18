<?php
declare(strict_types=1);

namespace PaySuite\Gateway\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const METHOD_CODE = 'paysuite';
    private const PATH = 'payment/paysuite/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isActive(?int $storeId = null): bool
    {
        return (bool) $this->get('active', $storeId);
    }

    public function getApiUrl(?int $storeId = null): string
    {
        return rtrim((string) $this->get('api_url', $storeId), '/');
    }

    public function getApiKey(?int $storeId = null): string
    {
        return $this->encryptor->decrypt((string) $this->get('api_key', $storeId));
    }

    public function getWebhookSecret(?int $storeId = null): string
    {
        return $this->encryptor->decrypt((string) $this->get('webhook_secret', $storeId));
    }

    /** Override opcional (sem campo no admin), útil em dev com túnel: config:set payment/paysuite/webhook_base_url https://xxx.ngrok.app */
    public function getWebhookBaseUrl(?int $storeId = null): string
    {
        return trim((string) $this->get('webhook_base_url', $storeId));
    }

    public function isDebug(?int $storeId = null): bool
    {
        return (bool) $this->get('debug', $storeId);
    }

    private function get(string $field, ?int $storeId = null): mixed
    {
        return $this->scopeConfig->getValue(
            self::PATH . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
