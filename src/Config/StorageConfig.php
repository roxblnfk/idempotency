<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Config;

use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\StorageFactoryInterface;

/**
 * Typed, data-only configuration of a single storage alias. Each driver ships a concrete subclass
 * carrying its own knobs (connection, table, TTLs, ...) and naming the factory that builds it — the
 * config never constructs a service itself.
 *
 * Mirrors the Cycle Database `DriverConfig` pattern: the config is the public, type-safe description
 * of a driver and points at the class that knows how to construct it.
 *
 * @api
 */
abstract class StorageConfig
{
    /**
     * Declared guarantee of this alias, verified fail-fast against the built driver's capability.
     */
    abstract public function guarantee(): Guarantee;

    /**
     * Factory that turns this config into a driver.
     *
     * @return class-string<StorageFactoryInterface>
     */
    abstract public function factory(): string;
}
