<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Driver\Cycle;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseProviderInterface;
use Psr\Clock\ClockInterface;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\Driver\Cycle\CycleGarbageCollector;
use Spiral\Idempotency\Driver\Redis\RedisLeaseConfig;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CycleGarbageCollector::class)]
final class CycleGarbageCollectorTest
{
    public function collectSkipsNonCycleStoragesWithoutTouchingTheDatabase(): void
    {
        // A Redis lease self-expires server-side, so GC has nothing to sweep. The provider stub throws
        // if resolved — proving collect() never opens a connection for a non-Cycle storage.
        $config = new IdempotencyConfig([
            'storages' => ['redis' => new RedisLeaseConfig()],
        ]);

        $databases = new class implements DatabaseProviderInterface {
            public function database(?string $database = null): DatabaseInterface
            {
                throw new \LogicException('GC must not touch the database for a non-Cycle storage.');
            }
        };

        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
            }
        };

        $gc = new CycleGarbageCollector($config, $databases, $clock);

        Assert::same($gc->collect(), []);
    }

    public function collectOnEmptyConfigReturnsEmptyArray(): void
    {
        $gc = new CycleGarbageCollector(
            new IdempotencyConfig(['storages' => []]),
            new class implements DatabaseProviderInterface {
                public function database(?string $database = null): DatabaseInterface
                {
                    throw new \LogicException('no storages => no database access');
                }
            },
            new class implements ClockInterface {
                public function now(): \DateTimeImmutable
                {
                    return new \DateTimeImmutable();
                }
            },
        );

        Assert::same($gc->collect(), []);
    }
}
