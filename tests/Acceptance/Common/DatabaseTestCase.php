<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Acceptance\Common;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseProviderInterface;
use Spiral\Idempotency\Tests\Acceptance\Testo\Db;
use Testo\Filter\Group;

/**
 * Base for acceptance tests that run against the driver matrix. The `#[Group('driver')]` marks the
 * whole family as driver-bound (so `--group=!driver` can skip it); each concrete subclass adds the
 * `#[Group('driver-<x>')]` that selects the actual connection.
 *
 * Tables are shared across all tests of a driver and never cleaned, so every test MUST use a distinct
 * idempotency key — call {@see self::key()} once and reuse the value within the test. See CLAUDE.md.
 */
#[Group('driver')]
abstract class DatabaseTestCase
{
    private static int $sequence = 0;

    final protected function manager(): DatabaseProviderInterface
    {
        return Db::manager();
    }

    final protected function db(string $name = 'default'): DatabaseInterface
    {
        return Db::database($name);
    }

    /**
     * A unique idempotency key, distinct across every call in the process — the only thing isolating
     * one test's rows from another's in the shared tables.
     *
     * The {@see self::runToken()} suffix keeps it distinct across separate runs too: the counter alone
     * restarts at 0 each process, so re-running against a still-populated database (containers left up
     * between runs) would otherwise reuse `k-1`, `k-2`, … and collide with the previous run's rows.
     *
     * @return non-empty-string
     */
    final protected function key(string $prefix = 'k'): string
    {
        return $prefix . '-' . self::runToken() . '-' . (++self::$sequence);
    }

    /**
     * A token unique to this test process, mixed into every generated key and table name so re-running
     * the suite against a persistent database cannot collide with rows a previous run left behind. The
     * PID is the same per-process discriminator the Redis lease test already uses for this reason.
     *
     * @return non-empty-string
     */
    final protected static function runToken(): string
    {
        return (string) \getmypid();
    }
}
