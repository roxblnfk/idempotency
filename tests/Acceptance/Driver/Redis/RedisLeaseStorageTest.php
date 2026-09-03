<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Acceptance\Driver\Redis;

use Spiral\Idempotency\Driver\Redis\Internal\RedisLeaseStorage;
use Spiral\Idempotency\Driver\Redis\PredisCommands;
use Spiral\Idempotency\Internal\Lease\DefaultLeaseManager;
use Spiral\Idempotency\Internal\Lease\RandomTokenFactory;
use Spiral\Idempotency\Lease\Acquired;
use Spiral\Idempotency\Lease\AlreadyCompleted;
use Spiral\Idempotency\Lease\LeaseState;
use Spiral\Idempotency\Lease\Locked;
use Spiral\Idempotency\Tests\Support\MutableClock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Filter\Group;
use Testo\Test;

/**
 * Lease (AtLeastOnce) storage over a real Redis/Valkey server. Self-contained: it does NOT use the SQL
 * {@see \Spiral\Idempotency\Tests\Acceptance\Testo\DatabaseInterceptor} (that only knows sqlite/pgsql/mysql
 * and ignores the `driver-redis` group). If no server is reachable the test skips (never fails).
 *
 * Because every test takes a process-unique key from {@see self::key()}, records never collide in the
 * shared keyspace and no FLUSHDB is needed.
 *
 * TTL-timing scenarios use a REAL short EXPIRE + usleep: Redis expiry is server-side wall-clock and is
 * NOT driven by the injected {@see MutableClock}.
 */
#[Test]
#[Covers(RedisLeaseStorage::class)]
#[Group('driver', 'driver-redis')]
final class RedisLeaseStorageTest
{
    private static int $sequence = 0;

    private ?\Predis\Client $client = null;

    // ----------------------------------------------------------------- CAS semantics

    public function acquireInsertsProcessingRecord(): void
    {
        $key = $this->key();
        $storage = $this->storage();

        Assert::true($storage->acquire($key, 'tok', 30));

        $entry = $storage->read($key);
        Assert::same($entry?->state, LeaseState::Processing);
        Assert::same($entry?->token, 'tok');
    }

    public function secondAcquireConflicts(): void
    {
        $key = $this->key();
        $storage = $this->storage();
        $storage->acquire($key, 'tok', 30);

        Assert::false($storage->acquire($key, 'other', 30));
    }

    public function completeStoresResultAndClearsToken(): void
    {
        $key = $this->key();
        $storage = $this->storage();
        $storage->acquire($key, 'tok', 30);

        Assert::true($storage->complete($key, 'tok', true, 'payload', 3600));

        $entry = $storage->read($key);
        Assert::same($entry?->state, LeaseState::Completed);
        Assert::same($entry?->result, 'payload');
        Assert::true($entry?->success);
        Assert::null($entry?->token);
    }

    public function completeWithNullResultStoresNoResultField(): void
    {
        $key = $this->key();
        $storage = $this->storage();
        $storage->acquire($key, 'tok', 30);

        Assert::true($storage->complete($key, 'tok', true, null, 3600));

        $entry = $storage->read($key);
        Assert::same($entry?->state, LeaseState::Completed);
        Assert::null($entry?->result);
    }

    public function completeWithWrongTokenFails(): void
    {
        $key = $this->key();
        $storage = $this->storage();
        $storage->acquire($key, 'tok', 30);

        Assert::false($storage->complete($key, 'wrong', true, 'x', 3600));
    }

    public function abortDeletesRecord(): void
    {
        $key = $this->key();
        $storage = $this->storage();
        $storage->acquire($key, 'tok', 30);

        Assert::true($storage->abort($key, 'tok'));
        Assert::null($storage->read($key));
    }

    public function renewIsNoOpSafeWithinSameSecond(): void
    {
        // Redis EXPIRE always applies (no MySQL changed-vs-matched quirk), but the invariant still holds:
        // the owner's renew succeeds while a non-owner token is rejected.
        $key = $this->key();
        $storage = $this->storage();
        $storage->acquire($key, 'tok', 30);

        Assert::true($storage->renew($key, 'tok', 30));   // still the owner
        Assert::false($storage->renew($key, 'other', 30)); // a non-owner token fails
    }

    public function managerFlowAcquireCompleteReplay(): void
    {
        $key = $this->key();
        $clock = new MutableClock();
        $manager = new DefaultLeaseManager($this->storage(), $clock, new RandomTokenFactory());

        $acquired = $manager->acquire($key, 30);
        Assert::instanceOf($acquired, Acquired::class);

        $manager->complete($key, $acquired->token, true, 'cached', 3600);

        $replay = $manager->acquire($key, 30);
        Assert::instanceOf($replay, AlreadyCompleted::class);
        Assert::same($replay->result, 'cached');
    }

    public function managerReportsLockedWhileProcessing(): void
    {
        $key = $this->key();
        $manager = new DefaultLeaseManager($this->storage(), new MutableClock(), new RandomTokenFactory());
        $manager->acquire($key, 30);

        Assert::instanceOf($manager->acquire($key, 30), Locked::class);
    }

    // ----------------------------------------------------------------- TTL timing (real sleeps)

    public function expiredRecordIsTakenOverOnAcquire(): void
    {
        // Sleeps because Redis expiry is real-time (server wall-clock), not driven by MutableClock.
        $key = $this->key();
        $storage = $this->storage();
        $storage->acquire($key, 'old', 1);

        \usleep(1_200_000); // let Redis expire the key

        Assert::true($storage->acquire($key, 'new', 30));
        Assert::same($storage->read($key)?->token, 'new');
    }

    public function readTreatsExpiredAsAbsent(): void
    {
        // Sleeps because Redis expiry is real-time (server wall-clock), not driven by MutableClock.
        $key = $this->key();
        $storage = $this->storage();
        $storage->acquire($key, 'tok', 1);

        \usleep(1_200_000);

        Assert::null($storage->read($key));
    }

    public function renewExtendsExpiry(): void
    {
        // Sleeps because Redis expiry is real-time (server wall-clock), not driven by MutableClock.
        $key = $this->key();
        $storage = $this->storage();
        $storage->acquire($key, 'tok', 1);

        // Renew to a comfortable TTL before the 1s lock lapses, then wait past the original expiry.
        Assert::true($storage->renew($key, 'tok', 30));
        \usleep(1_200_000);

        Assert::same($storage->read($key)?->state, LeaseState::Processing);
    }

    // ----------------------------------------------------------------- helpers

    private function storage(): RedisLeaseStorage
    {
        // A unique prefix per test process run keeps this suite's keys clear of any pre-existing data.
        return new RedisLeaseStorage(new PredisCommands($this->client()), new MutableClock(), 'idempotency-test:');
    }

    /**
     * @return non-empty-string
     */
    private function key(string $prefix = 'k'): string
    {
        return $prefix . '-' . (++self::$sequence) . '-' . \getmypid();
    }

    private function client(): \Predis\Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $host = \getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (\getenv('REDIS_PORT') ?: 16379);

        $client = new \Predis\Client(['host' => $host, 'port' => $port]);
        try {
            $client->ping();
        } catch (\Throwable $e) {
            throw new SkipTest(\sprintf('Redis/Valkey not available at %s:%d (%s).', $host, $port, $e->getMessage()));
        }

        return $this->client = $client;
    }
}
