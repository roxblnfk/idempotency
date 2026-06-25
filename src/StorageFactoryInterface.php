<?php

declare(strict_types=1);

namespace Spiral\Idempotency;

use Spiral\Idempotency\Config\StorageConfig;

/**
 * Builds a driver from its {@see StorageConfig} data and the runtime {@see StorageServices}. A driver
 * ships one factory; the config only points at it by class-string, so configuration stays pure data.
 *
 * Implementations must be instantiable without constructor arguments (everything needed arrives via
 * {@see StorageServices}); override the bootloader if container-resolved factories are required.
 *
 * @api
 */
interface StorageFactoryInterface
{
    public function create(StorageConfig $config, StorageServices $services): IdempotencyInterface;
}
