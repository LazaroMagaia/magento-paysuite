<?php
declare(strict_types=1);

namespace PaySuite\Gateway\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\LaminasClientFactory;
use PaySuite\Gateway\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Cliente HTTP fino para a API PaySuite.
 * Config "URL da API" deve incluir a versão, ex.: https://paysuite.tech/api/v1
 */
class Client
{
    private const TIMEOUT = 15;

    public function __construct(
        private readonly LaminasClientFactory $clientFactory,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array Resposta decodificada ({"status":"success", ...})
     * @throws LocalizedException
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        ?int $storeId = null
    ): array {
        $client = $this->clientFactory->create();
        $client->setUri($this->config->getApiUrl($storeId) . '/' . ltrim($path, '/'));
        $client->setMethod($method);
        $client->setOptions(['timeout' => self::TIMEOUT, 'maxredirects' => 0]);
        $client->setHeaders([
            'Authorization' => 'Bearer ' . $this->config->getApiKey($storeId),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ]);

        if ($query) {
            $client->setParameterGet($query);
        }
        if ($body !== null) {
            $client->setRawBody(json_encode($body, JSON_THROW_ON_ERROR));
        }

        try {
            $response = $client->send();
        } catch (\Throwable $e) {
            $this->logger->error('PaySuite API: falha de ligação', ['path' => $path, 'error' => $e->getMessage()]);
            throw new LocalizedException(__('Não foi possível contactar a PaySuite. Tente novamente.'), $e);
        }

        $code    = $response->getStatusCode();
        $decoded = json_decode($response->getBody(), true);

        if ($this->config->isDebug($storeId)) {
            $this->logger->info('PaySuite API', ['method' => $method, 'path' => $path, 'http' => $code]);
        }

        if ($code < 200 || $code >= 300 || !is_array($decoded) || ($decoded['status'] ?? '') !== 'success') {
            $message = is_array($decoded) ? (string) ($decoded['message'] ?? 'Erro desconhecido') : 'Resposta inválida';
            $this->logger->error('PaySuite API: erro', ['path' => $path, 'http' => $code, 'message' => $message]);
            throw new LocalizedException(__('PaySuite: %1', $message));
        }

        return $decoded;
    }
}
