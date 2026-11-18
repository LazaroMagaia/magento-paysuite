<?php
declare(strict_types=1);

namespace PaySuite\Gateway\Model\Webhook;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;

/**
 * Idempotência: chave = event + resource id (ex.: "payment.success" + id).
 */
class EventLog
{
    private const TABLE = 'paysuite_webhook_event';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /** Devolve false se o evento já foi processado/reservado. */
    public function claim(string $event, string $resourceId): bool
    {
        try {
            $this->resource->getConnection()->insert(
                $this->resource->getTableName(self::TABLE),
                ['event' => $event, 'resource_id' => $resourceId]
            );
            return true;
        } catch (DuplicateException) {
            return false;
        }
    }

    /** Liberta a reserva para que um retry da PaySuite possa reprocessar. */
    public function release(string $event, string $resourceId): void
    {
        $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['event = ?' => $event, 'resource_id = ?' => $resourceId]
        );
    }
}
