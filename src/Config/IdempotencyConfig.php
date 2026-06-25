<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Config;

use Spiral\Core\InjectableConfig;
use Spiral\Idempotency\Exception\MisconfigurationException;

/**
 * Storage-alias configuration. A handler method carries a semantic alias; the driver, connection and
 * declared guarantee live here as a typed {@see StorageConfig} DTO. Example `app/config/idempotency.php`:
 *
 * ```php
 * use Spiral\Idempotency\Driver\Cycle\CycleInboxConfig;
 * use Spiral\Idempotency\Driver\Cycle\CycleLeaseConfig;
 *
 * return [
 *     'default' => 'orders',
 *     'storages' => [
 *         'orders'        => new CycleInboxConfig(connection: 'default', table: 'inbox'),
 *         'notifications' => new CycleLeaseConfig(connection: 'default', lockTtl: 30, retentionTtl: 86400),
 *     ],
 * ];
 * ```
 *
 * @api
 */
final class IdempotencyConfig extends InjectableConfig
{
    public const CONFIG = 'idempotency';

    protected array $config = [
        'default' => null,
        'storages' => [],
    ];

    /**
     * @return array<non-empty-string, StorageConfig>
     */
    public function getStorages(): array
    {
        /** @var array<non-empty-string, StorageConfig> */
        return $this->config['storages'] ?? [];
    }

    /**
     * @param non-empty-string $alias
     */
    public function getStorage(string $alias): StorageConfig
    {
        return $this->getStorages()[$alias] ?? throw new MisconfigurationException(
            \sprintf('No idempotency storage configured under alias "%s".', $alias),
        );
    }

    /**
     * @return non-empty-string|null
     */
    public function getDefault(): ?string
    {
        /** @var non-empty-string|null */
        return $this->config['default'] ?? null;
    }
}
