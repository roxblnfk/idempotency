<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle;

use Cycle\Transaction\FlushMode;
use Cycle\Transaction\TransactionMode;
use Spiral\Idempotency\Config\StorageConfig;
use Spiral\Idempotency\Driver\Cycle\Internal\CycleInboxFactory;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\StorageFactoryInterface;

/**
 * Data-only config for an ExactlyOnce-effect inbox in a Cycle DBAL connection.
 *
 * Security: cached results are (de)serialized by the storage's {@see \Spiral\Serializer\SerializerInterface}.
 * The default {@see \Spiral\Serializer\Serializer\PhpSerializer} restores them via `unserialize()` on replay,
 * so the inbox table is the trust boundary. If several services or roles write into it, bind a JSON
 * serializer and return JSON-safe results (see {@see \Spiral\Idempotency\Bootloader\IdempotencyBootloader}).
 *
 * @api
 */
final class CycleInboxConfig extends StorageConfig
{
    /**
     * @param string|null $connection DBAL database name (null = the default database)
     * @param non-empty-string $table inbox table
     * @param TransactionMode $transactionMode how the transaction is opened. Default
     *        {@see TransactionMode::Exclusive}: the inbox transaction must be top-level (it throws if
     *        an outer transaction is already open), guaranteeing the committed dedup record + side-effect
     *        cannot be silently rolled back by a surrounding transaction. {@see TransactionMode::OpenNew}
     *        also opens its own transaction but tolerates an outer one; {@see TransactionMode::Current}
     *        joins an already-open transaction; {@see TransactionMode::Ignore} does not manage one.
     * @param FlushMode $flushMode when the scoped Entity Manager flushes its pending changes
     * @param Guarantee $guarantee declared guarantee (must be backable by the inbox driver)
     */
    public function __construct(
        public readonly ?string $connection = null,
        public readonly string $table = 'inbox',
        public readonly TransactionMode $transactionMode = TransactionMode::Exclusive,
        public readonly FlushMode $flushMode = FlushMode::BeforeCommit,
        public readonly Guarantee $guarantee = Guarantee::ExactlyOnce,
    ) {}

    public function guarantee(): Guarantee
    {
        return $this->guarantee;
    }

    /**
     * @return class-string<StorageFactoryInterface>
     */
    public function factory(): string
    {
        return CycleInboxFactory::class;
    }
}
