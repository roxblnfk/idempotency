<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Lease;

use Spiral\Idempotency\Exception\CachedDomainFailureException;
use Spiral\Idempotency\Exception\LockedException;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\Internal\Lease\LeaseIdempotency;
use Spiral\Idempotency\Internal\Lease\LeaseManager;
use Spiral\Idempotency\Internal\Lease\Storage\InMemoryLeaseStorage;
use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\Tests\Support\MutableClock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(LeaseIdempotency::class)]
final class LeaseIdempotencyTest
{
    private function driver(?MutableClock $clock = null, ?DefaultFailureClassifier $classifier = null): LeaseIdempotency
    {
        $clock ??= new MutableClock();
        $manager = new LeaseManager(new InMemoryLeaseStorage($clock), $clock);

        return new LeaseIdempotency($manager, lockTtl: 30, retentionTtl: 3600, classifier: $classifier);
    }

    public function executesAndReturnsResult(): void
    {
        $result = $this->driver()->execute('k', static fn(IdempotencyContext $c): string => 'done:' . $c->getKey());

        Assert::same($result, 'done:k');
    }

    public function replaysCachedResultWithoutReExecuting(): void
    {
        $driver = $this->driver();
        $calls = 0;
        $op = static function () use (&$calls): string {
            ++$calls;
            return 'value';
        };

        $first = $driver->execute('k', $op);
        $second = $driver->execute('k', $op);

        Assert::same($first, 'value');
        Assert::same($second, 'value');
        Assert::same($calls, 1);
    }

    public function voidResultRoundTrips(): void
    {
        $driver = $this->driver();
        $calls = 0;
        $op = static function () use (&$calls): null {
            ++$calls;
            return null;
        };

        Assert::null($driver->execute('k', $op));
        Assert::null($driver->execute('k', $op));
        Assert::same($calls, 1);
    }

    public function domainFailureIsCachedAndRethrownOnReplay(): never
    {
        $driver = $this->driver();
        $calls = 0;
        $op = static function () use (&$calls): never {
            ++$calls;
            throw new \RuntimeException('insufficient funds');
        };

        try {
            $driver->execute('k', $op);
        } catch (\RuntimeException) {
            // first call: the domain failure is cached
        }

        Assert::same($calls, 1);

        Expect::exception(CachedDomainFailureException::class)->withMessage('insufficient funds');

        // replay rethrows the cached outcome (deterministic snapshot) without re-executing
        $driver->execute('k', $op);
    }

    public function cachedDomainFailureKeepsOriginalClass(): void
    {
        $driver = $this->driver();
        $op = static function (): never {
            throw new \DomainException('rejected');
        };

        try {
            $driver->execute('k', $op);
        } catch (\DomainException) {
        }

        try {
            $driver->execute('k', $op);
            Assert::fail('replay should rethrow');
        } catch (CachedDomainFailureException $replayed) {
            Assert::same($replayed->originalClass, \DomainException::class);
            Assert::same($replayed->getMessage(), 'rejected');
        }
    }

    public function infrastructureFailureAbortsAndAllowsRetry(): void
    {
        $driver = $this->driver();
        $calls = 0;
        $op = static function () use (&$calls): never {
            ++$calls;
            throw new \Error('transient');
        };

        foreach (['first', 'second'] as $_) {
            try {
                $driver->execute('k', $op);
            } catch (\Error) {
                // \Error → Infrastructure → abort() frees the key for a retry
            }
        }

        Assert::same($calls, 2);
    }

    public function bugFailureFreesTheKey(): void
    {
        $classifier = new DefaultFailureClassifier(bugExceptions: [\LogicException::class]);
        $driver = $this->driver(classifier: $classifier);
        $calls = 0;
        $op = static function () use (&$calls): never {
            ++$calls;
            throw new \LogicException('bug');
        };

        foreach (['first', 'second'] as $_) {
            try {
                $driver->execute('k', $op);
            } catch (\LogicException) {
                // Bug → error() deletes the record (not persisted), key is free again
            }
        }

        Assert::same($calls, 2);
    }

    public function lockedKeyThrowsLockedException(): never
    {
        $clock = new MutableClock();
        $manager = new LeaseManager(new InMemoryLeaseStorage($clock), $clock);
        $driver = new LeaseIdempotency($manager, lockTtl: 30, retentionTtl: 3600);

        // Occupy the key with an in-flight PROCESSING lease held by "someone else".
        $manager->acquire('k', 30);

        Expect::exception(LockedException::class);

        $driver->execute('k', static fn(): string => 'never reached');
    }
}
