<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Internal\Lease;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Spiral\Idempotency\Exception\LeaseLostException;
use Spiral\Idempotency\Internal\Lease\HeartbeatThrottle;
use Spiral\Idempotency\Tests\Support\MutableClock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(HeartbeatThrottle::class)]
final class HeartbeatThrottleTest
{
    public function forcedBeatAlwaysRenews(): void
    {
        $clock = new MutableClock();
        $beats = 0;
        $renew = function () use (&$beats): void {
            $beats++;
        };
        $throttle = new HeartbeatThrottle($clock, $renew, lockTtl: 30, threshold: 0.5, key: 'k');

        $throttle(true);
        $throttle(true);
        $throttle(true);

        Assert::same($beats, 3);
    }

    public function unforcedBeatThrottledWithinWindow(): void
    {
        $clock = new MutableClock();
        $beats = 0;
        $renew = function () use (&$beats): void {
            $beats++;
        };
        $throttle = new HeartbeatThrottle($clock, $renew, lockTtl: 30, threshold: 0.5, key: 'k');

        $throttle(false);
        Assert::same($beats, 0);

        $clock->advance(10);
        $throttle(false);
        Assert::same($beats, 0);

        $clock->advance(6); // now 16s since anchor >= 15
        $throttle(false);
        Assert::same($beats, 1);

        $clock->advance(10); // 10s since last renew (at 16) < 15
        $throttle(false);
        Assert::same($beats, 1);

        $clock->advance(6); // now 16 + 10 + 6 = 32, i.e. 16s since last renew >= 15
        $throttle(false);
        Assert::same($beats, 2);
    }

    public function forcedBeatResetsTheWindow(): void
    {
        $clock = new MutableClock();
        $beats = 0;
        $renew = function () use (&$beats): void {
            $beats++;
        };
        $throttle = new HeartbeatThrottle($clock, $renew, lockTtl: 30, threshold: 0.5, key: 'k');

        $clock->advance(5);
        $throttle(true);
        Assert::same($beats, 1);

        $clock->advance(10); // 10s since reset < 15
        $throttle(false);
        Assert::same($beats, 1);

        $clock->advance(5); // 15s since reset >= 15
        $throttle(false);
        Assert::same($beats, 2);
    }

    public function leaseLostIsSwallowedAndStopsFurtherBeats(): void
    {
        $clock = new MutableClock();
        $attempts = 0;
        $renew = function () use (&$attempts): void {
            $attempts++;
            throw new LeaseLostException('lease taken over');
        };
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $throttle = new HeartbeatThrottle($clock, $renew, lockTtl: 30, threshold: 0.5, key: 'k', logger: $logger);

        $throttle(true); // must not throw — the exception is swallowed

        $throttle(true); // the lost flag disables further attempts

        Assert::same($attempts, 1);
        Assert::same(\count($logger->records), 1);
        Assert::same($logger->records[0]['level'], LogLevel::WARNING);
    }
}
