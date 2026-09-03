<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle;

use Spiral\Idempotency\Config\StorageConfig;
use Spiral\Idempotency\Driver\Cycle\Internal\CycleLeaseFactory;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\StorageFactory;

/**
 * Data-only config for an AtLeastOnce lease over a Cycle DBAL connection.
 *
 * Security: cached results are (de)serialized by the storage's {@see \Spiral\Serializer\SerializerInterface}.
 * The default {@see \Spiral\Serializer\Serializer\PhpSerializer} restores them via `unserialize()` on replay,
 * so the lease table is the trust boundary. If several services or roles write into it, bind a JSON
 * serializer and return JSON-safe results (see {@see \Spiral\Idempotency\Bootloader\IdempotencyBootloader}).
 *
 * @api
 */
final class CycleLeaseConfig extends StorageConfig
{
    /**
     * @param string|null $connection DBAL database name (null = the default database)
     * @param non-empty-string $table lease table
     * @param int<1, max> $lockTtl PROCESSING lock TTL, seconds
     * @param int<1, max> $retentionTtl COMPLETED retention TTL, seconds
     * @param Guarantee $guarantee declared guarantee (must be backable by the lease driver)
     * @param float $heartbeatThreshold fraction of lockTtl an unforced heartbeat waits before it renews
     *        again (0..1). Throttles {@see \Spiral\Idempotency\IdempotencyContext::renew()} so a
     *        long-running operation keeps its lock alive without hammering the storage. Default 0.5 =
     *        renew at most once per half the lockTtl.
     */
    public function __construct(
        public readonly ?string $connection = null,
        public readonly string $table = 'idempotency',
        public readonly int $lockTtl = 30,
        public readonly int $retentionTtl = 86400,
        public readonly Guarantee $guarantee = Guarantee::AtLeastOnce,
        public readonly float $heartbeatThreshold = 0.5,
    ) {}

    public function guarantee(): Guarantee
    {
        return $this->guarantee;
    }

    /**
     * @return class-string<StorageFactory>
     */
    public function factory(): string
    {
        return CycleLeaseFactory::class;
    }
}
