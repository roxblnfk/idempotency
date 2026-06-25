<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle;

use Spiral\Idempotency\Config\StorageConfig;
use Spiral\Idempotency\Driver\Cycle\Internal\CycleLeaseFactory;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\StorageFactoryInterface;

/**
 * Data-only config for an AtLeastOnce lease over a Cycle DBAL connection.
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
     */
    public function __construct(
        public readonly ?string $connection = null,
        public readonly string $table = 'idempotency',
        public readonly int $lockTtl = 30,
        public readonly int $retentionTtl = 86400,
        public readonly Guarantee $guarantee = Guarantee::AtLeastOnce,
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
        return CycleLeaseFactory::class;
    }
}
