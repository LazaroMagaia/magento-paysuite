<?php
declare(strict_types=1);

namespace PaySuite\Gateway\Controller\Webhook;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use PaySuite\Gateway\Model\Config;
use PaySuite\Gateway\Model\Webhook\EventLog;
use PaySuite\Gateway\Model\Webhook\Processor;
use Psr\Log\LoggerInterface;

/**
 * POST /paysuite/webhook
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly Processor $processor,
        private readonly EventLog $eventLog,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $payload   = (string) $this->request->getContent();
        $signature = strtolower(trim((string) $this->request->getHeader('X-Signature')));
        $secret    = $this->config->getWebhookSecret();

        if ($secret === '') {
            $this->logger->error('PaySuite webhook: webhook_secret não configurado.');
            return $this->respond(500, 'Webhook secret not configured');
        }

        if ($signature === '' || !hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
            $this->logger->warning('PaySuite webhook: assinatura inválida.');
            return $this->respond(401, 'Invalid signature');
        }

        $body  = json_decode($payload, true);
        $event = is_array($body) ? (string) ($body['event'] ?? '') : '';
        $data  = is_array($body) ? ($body['data'] ?? null) : null;
        $id    = is_array($data) ? (string) ($data['id'] ?? '') : '';

        if ($event === '' || $id === '') {
            return $this->respond(400, 'Invalid payload');
        }

        if (!$this->eventLog->claim($event, $id)) {
            return $this->respond(200, 'Already processed');
        }

        try {
            $this->processor->process($event, $data);
        } catch (NoSuchEntityException $e) {
            $this->eventLog->release($event, $id);
            $this->logger->warning('PaySuite webhook: ' . $e->getMessage());
            return $this->respond(404, 'Resource not found');
        } catch (\Throwable $e) {
            $this->eventLog->release($event, $id);
            $this->logger->error('PaySuite webhook error: ' . $e->getMessage(), ['exception' => $e]);
            return $this->respond(500, 'Processing error');
        }

        return $this->respond(200, 'OK');
    }

    private function respond(int $code, string $message): ResultInterface
    {
        return $this->jsonFactory->create()
            ->setHttpResponseCode($code)
            ->setData(['status' => $code < 300 ? 'success' : 'error', 'message' => $message]);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true; // autenticado por HMAC, não por form key
    }
}
