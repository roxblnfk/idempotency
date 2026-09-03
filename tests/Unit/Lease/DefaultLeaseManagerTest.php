<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Lease;

use Spiral\Idempotency\Exception\LeaseLostException;
use Spiral\Idempotency\Lease\Acquired;
use Spiral\Idempotency\Lease\AcquireRetry;
use Spiral\Idempotency\Lease\AlreadyCompleted;
use Spiral\Idempotency\Internal\Lease\DefaultLeaseManager;
use Spiral\Idempotency\Lease\LeaseStorage;
use Spiral\Idempotency\Lease\Locked;
use Spiral\Idempotency\Internal\Lease\Storage\InMemoryLeaseStorage;
use Spiral\Idempotency\Lease\StoredEntry;
use Spiral\Idempotency\Tests\Support\MutableClock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(DefaultLeaseManager::class)]
final class DefaultLeaseManagerTest
{
    private function manager(MutableClock $clock): DefaultLeaseManager
    {
        return new DefaultLeaseManager(new InMemoryLeaseStorage($clock), $clock);
    }

    public function acquireOnFreeKeyReturnsAcquired(): void
    {
        $manager = $this->manager(new MutableClock());

        $result = $manager->acquire('k', 30);

        Assert::instanceOf($result, Acquired::class);
        Assert::same($result->key, 'k');
        Assert::true($result->token !== '');
    }

    public function secondAcquireWhileProcessingReturnsLocked(): void
    {
        $manager = $this->manager(new MutableClock());
        $manager->acquire('k', 30);

        $result = $manager->acquire('k', 30);

        Assert::instanceOf($result, Locked::class);
        Assert::same($result->retryAfter, 30);
    }

    public function acquireAfterCompleteReturnsAlreadyCompleted(): void
    {
        $manager = $this->manager(new MutableClock());
        $acquired = $manager->acquire('k', 30);
        Assert::instanceOf($acquired, Acquired::class);

        $manager->complete('k', $acquired->token, true, 'cached-payload', 3600);
        $result = $manager->acquire('k', 30);

        Assert::instanceOf($result, AlreadyCompleted::class);
        Assert::true($result->success);
        Assert::same($result->result, 'cached-payload');
    }

    public function alreadyCompletedCarriesDomainFailureFlag(): void
    {
        $manager = $this->manager(new MutableClock());
        $acquired = $manager->acquire('k', 30);
        Assert::instanceOf($acquired, Acquired::class);

        $manager->complete('k', $acquired->token, false, 'domain-blob', 3600);
        $result = $manager->acquire('k', 30);

        Assert::instanceOf($result, AlreadyCompleted::class);
        Assert::false($result->success);
    }

    public function completeWithWrongTokenIsRejected(): never
    {
        $manager = $this->manager(new MutableClock());
        $manager->acquire('k', 30);

        Expect::exception(LeaseLostException::class);

        $manager->complete('k', 'not-the-owner', true, null, 3600);
    }

    public function abortFreesTheKey(): void
    {
        $manager = $this->manager(new MutableClock());
        $acquired = $manager->acquire('k', 30);
        Assert::instanceOf($acquired, Acquired::class);

        $manager->abort('k', $acquired->token);

        Assert::instanceOf($manager->acquire('k', 30), Acquired::class);
    }

    public function errorFreesTheKey(): void
    {
        $manager = $this->manager(new MutableClock());
        $acquired = $manager->acquire('k', 30);
        Assert::instanceOf($acquired, Acquired::class);

        $manager->error('k', $acquired->token);

        Assert::instanceOf($manager->acquire('k', 30), Acquired::class);
    }

    public function expiredLockIsTakenOverOnNextAcquire(): void
    {
        $clock = new MutableClock();
        $manager = $this->manager($clock);
        $manager->acquire('k', 10);

        $clock->advance(11);

        Assert::instanceOf($manager->acquire('k', 10), Acquired::class);
    }

    public function renewExtendsTheLock(): void
    {
        $clock = new MutableClock();
        $manager = $this->manager($clock);
        $acquired = $manager->acquire('k', 10);
        Assert::instanceOf($acquired, Acquired::class);

        $clock->advance(8);
        $manager->renew('k', $acquired->token, 10);
        $clock->advance(5); // 13s since start, but only 5s since renew

        Assert::instanceOf($manager->acquire('k', 10), Locked::class);
    }

    public function acquireRetryWhenRecordVanishesInConflictBranch(): void
    {
        // Storage that reports a conflict but then has nothing to read — the gap of spec §3.2.1.
        $storage = new class implements LeaseStorage {
            public function acquire(string $key, string $token, int $lockTtl): bool
            {
                return false;
            }

            public function complete(string $key, string $token, bool $success, mixed $result, int $retentionTtl): bool
            {
                return false;
            }

            public function abort(string $key, string $token): bool
            {
                return false;
            }

            public function error(string $key, string $token): bool
            {
                return false;
            }

            public function renew(string $key, string $token, int $lockTtl): bool
            {
                return false;
            }

            public function read(string $key): ?StoredEntry
            {
                return null;
            }
        };

        $manager = new DefaultLeaseManager($storage, new MutableClock());

        $result = $manager->acquire('k', 30);

        Assert::instanceOf($result, AcquireRetry::class);
    }
}
