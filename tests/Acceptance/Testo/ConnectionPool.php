<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Acceptance\Testo;

use Cycle\Database\DatabaseManager;

/**
 * Per-suite cache of one {@see DatabaseManager} per driver, plus a "tables prepared" flag so the
 * idempotency tables are created only once per driver (we never recreate or clean them between
 * tests — isolation comes from each test using a distinct idempotency key).
 */
final class ConnectionPool
{
    /** @var array<value-of<DatabaseDriver>, DatabaseManager> */
    private array $managers = [];

    /** @var array<value-of<DatabaseDriver>, true> */
    private array $prepared = [];

    public function manager(DatabaseDriver $driver): DatabaseManager
    {
        return $this->managers[$driver->value] ??= $driver->manager();
    }

    public function isPrepared(DatabaseDriver $driver): bool
    {
        return isset($this->prepared[$driver->value]);
    }

    public function markPrepared(DatabaseDriver $driver): void
    {
        $this->prepared[$driver->value] = true;
    }
}
