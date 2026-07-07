<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Acceptance\Common;

use Cycle\Database\DatabaseProviderInterface;
use Cycle\ORM\Factory;
use Cycle\ORM\ORM;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\Schema;
use Cycle\Transaction\Internal\TransactionImpl;
use Spiral\Core\Container;
use Spiral\Idempotency\Bootloader\IdempotencyBootloader;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\Driver\Cycle\CycleContext;
use Spiral\Idempotency\Driver\Cycle\CycleInboxConfig;
use Spiral\Idempotency\Driver\Cycle\CycleLeaseConfig;
use Spiral\Idempotency\Driver\Cycle\Internal\CycleInboxDriver;
use Spiral\Idempotency\Driver\Cycle\Internal\CycleLeaseStorage;
use Spiral\Idempotency\Exception\LeaseLostException;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Internal\Lease\LeaseIdempotency;
use Spiral\Idempotency\Internal\Lease\LeaseManager;
use Spiral\Idempotency\Internal\Lease\RandomTokenFactory;
use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\Lease\Acquired;
use Spiral\Idempotency\Lease\AlreadyCompleted;
use Spiral\Idempotency\Lease\LeaseState;
use Spiral\Idempotency\Lease\Locked;
use Spiral\Idempotency\Lease\TokenFactoryInterface;
use Spiral\Idempotency\Pipeline\Middleware\ClassifierMiddleware;
use Spiral\Idempotency\Pipeline\Pipeline;
use Spiral\Idempotency\Tests\Support\MutableClock;
use Spiral\Serializer\SerializerInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;

/**
 * Lease (AtLeastOnce) + inbox (ExactlyOnce) storage and bootloader wiring, exercised against a real
 * connection. Each test takes a unique key from {@see DatabaseTestCase::key()} so its rows never
 * collide with another test's in the shared, never-cleaned tables.
 */
#[Covers(CycleLeaseStorage::class)]
#[Covers(CycleInboxDriver::class)]
#[Covers(IdempotencyBootloader::class)]
#[Covers(IdempotencyConfig::class)]
abstract class CycleStorageTestCase extends DatabaseTestCase
{
    // ----------------------------------------------------------------- lease storage

    public function acquireInsertsProcessingRecord(): void
    {
        $key = $this->key();
        $storage = new CycleLeaseStorage($this->db(), new MutableClock());

        Assert::true($storage->acquire($key, 'tok', 30));

        $entry = $storage->read($key);
        Assert::same($entry?->state, LeaseState::Processing);
        Assert::same($entry?->token, 'tok');
    }

    public function secondAcquireConflicts(): void
    {
        $key = $this->key();
        $storage = new CycleLeaseStorage($this->db(), new MutableClock());
        $storage->acquire($key, 'tok', 30);

        Assert::false($storage->acquire($key, 'other', 30));
    }

    public function completeStoresResultAndClearsToken(): void
    {
        $key = $this->key();
        $storage = new CycleLeaseStorage($this->db(), new MutableClock());
        $storage->acquire($key, 'tok', 30);

        Assert::true($storage->complete($key, 'tok', true, 'payload', 3600));

        $entry = $storage->read($key);
        Assert::same($entry?->state, LeaseState::Completed);
        Assert::same($entry?->result, 'payload');
        Assert::true($entry?->success);
        Assert::null($entry?->token);
    }

    public function completeWithWrongTokenFails(): void
    {
        $key = $this->key();
        $storage = new CycleLeaseStorage($this->db(), new MutableClock());
        $storage->acquire($key, 'tok', 30);

        Assert::false($storage->complete($key, 'wrong', true, 'x', 3600));
    }

    public function abortDeletesRecord(): void
    {
        $key = $this->key();
        $storage = new CycleLeaseStorage($this->db(), new MutableClock());
        $storage->acquire($key, 'tok', 30);

        Assert::true($storage->abort($key, 'tok'));
        Assert::null($storage->read($key));
    }

    public function expiredRecordIsTakenOverOnAcquire(): void
    {
        $key = $this->key();
        $clock = new MutableClock();
        $storage = new CycleLeaseStorage($this->db(), $clock);
        $storage->acquire($key, 'old', 10);

        $clock->advance(11);

        Assert::true($storage->acquire($key, 'new', 10));
        Assert::same($storage->read($key)?->token, 'new');
    }

    public function readTreatsExpiredAsAbsent(): void
    {
        $key = $this->key();
        $clock = new MutableClock();
        $storage = new CycleLeaseStorage($this->db(), $clock);
        $storage->acquire($key, 'tok', 10);

        $clock->advance(11);

        Assert::null($storage->read($key));
    }

    public function renewExtendsExpiry(): void
    {
        $key = $this->key();
        $clock = new MutableClock();
        $storage = new CycleLeaseStorage($this->db(), $clock);
        $storage->acquire($key, 'tok', 10);

        $clock->advance(8);
        Assert::true($storage->renew($key, 'tok', 10));
        $clock->advance(5);

        Assert::same($storage->read($key)?->state, LeaseState::Processing);
    }

    public function renewIsNoOpSafeWithinSameSecond(): void
    {
        // MySQL rowCount() reports *changed* rows: renewing to the same expire_time (clock not advanced,
        // same TTL) changes nothing and returns 0 — yet we are still the owner, so renew must succeed.
        $key = $this->key();
        $clock = new MutableClock();
        $storage = new CycleLeaseStorage($this->db(), $clock);
        $storage->acquire($key, 'tok', 30);

        Assert::true($storage->renew($key, 'tok', 30));  // no-op update, still owned
        Assert::false($storage->renew($key, 'other', 30)); // a non-owner token still fails
    }

    public function managerFlowAcquireCompleteReplay(): void
    {
        $key = $this->key();
        $clock = new MutableClock();
        $manager = new LeaseManager(new CycleLeaseStorage($this->db(), $clock), $clock);

        $acquired = $manager->acquire($key, 30);
        Assert::instanceOf($acquired, Acquired::class);

        $manager->complete($key, $acquired->token, true, 'cached', 3600);

        $replay = $manager->acquire($key, 30);
        Assert::instanceOf($replay, AlreadyCompleted::class);
        Assert::same($replay->result, 'cached');
    }

    public function takeoverThenOriginalOwnerCompletes(): void
    {
        $key = $this->key();
        $clock = new MutableClock();
        $storage = new CycleLeaseStorage($this->db(), $clock);

        // The original owner ("old") runs through the LeaseIdempotency handler; its operation takes long
        // enough for the lock TTL to lapse and a second worker ("new") to take the key over mid-flight.
        $oldTokens = new class implements TokenFactoryInterface {
            public function create(): string
            {
                return 'old';
            }
        };
        $handler = new LeaseIdempotency(
            new LeaseManager($storage, $clock, $oldTokens),
            new Pipeline(new ClassifierMiddleware(new DefaultFailureClassifier())),
        );

        $result = $handler->execute($key, function () use ($storage, $clock, $key): string {
            $clock->advance(11); // lock TTL (10s) lapses
            Assert::true($storage->acquire($key, 'new', 30)); // "new" takes over the expired lease
            return 'v';
        }, new ExecuteOptions(lockTtl: 10));

        // The operation's value reaches the caller even though complete() was CAS-rejected...
        Assert::same($result, 'v');
        // ...and the stored record still belongs to the new owner — the loss did not overwrite it.
        $entry = $storage->read($key);
        Assert::same($entry?->token, 'new');
        Assert::same($entry?->state, LeaseState::Processing);

        // The manager's strict contract is intact: a stale owner completing directly still throws.
        $staleManager = new LeaseManager($storage, $clock, $oldTokens);
        try {
            $staleManager->complete($key, 'old', true, 'ignored', 3600);
            Assert::fail('the stale owner must be rejected by the manager CAS');
        } catch (LeaseLostException) {
            // expected — only the handler forgives the loss, the manager signals it
        }

        // The rejected direct complete left the new owner's record untouched.
        Assert::same($storage->read($key)?->token, 'new');
    }

    public function managerReportsLockedWhileProcessing(): void
    {
        $key = $this->key();
        $clock = new MutableClock();
        $manager = new LeaseManager(new CycleLeaseStorage($this->db(), $clock), $clock);
        $manager->acquire($key, 30);

        Assert::instanceOf($manager->acquire($key, 30), Locked::class);
    }

    // ----------------------------------------------------------------- inbox driver

    public function inboxExecutesOnceAndReturnsResult(): void
    {
        $key = $this->key();

        $result = $this->inboxDriver()->execute($key, static fn(IdempotencyContext $c): string => 'ok:' . $c->getKey());

        Assert::same($result, 'ok:' . $key);
    }

    public function inboxDeduplicatesAndReplaysCachedResult(): void
    {
        $key = $this->key();
        $driver = $this->inboxDriver();
        $calls = 0;
        $op = static function () use (&$calls): string {
            ++$calls;
            return 'value';
        };

        Assert::same($driver->execute($key, $op), 'value');
        Assert::same($driver->execute($key, $op), 'value');
        Assert::same($calls, 1);
    }

    public function inboxSideEffectAndDedupCommitTogether(): void
    {
        $key = $this->key();
        $driver = $this->inboxDriver();
        $op = function (IdempotencyContext $c) use ($key): string {
            \assert($c instanceof CycleContext);
            $c->database()->insert('ledger')->values(['note' => $key])->run();
            return 'done';
        };

        $driver->execute($key, $op);
        $driver->execute($key, $op); // dedup — must NOT write a second ledger row

        Assert::same($this->ledgerCount($key), 1);
    }

    public function inboxRollbackOnFailureLeavesNoTraceAndAllowsRetry(): void
    {
        $key = $this->key();
        $driver = $this->inboxDriver();
        $attempt = 0;
        $op = function (IdempotencyContext $c) use (&$attempt, $key): string {
            ++$attempt;
            \assert($c instanceof CycleContext);
            $c->database()->insert('ledger')->values(['note' => $key])->run();
            if ($attempt === 1) {
                throw new \RuntimeException('boom'); // rolls back the inbox row AND the ledger insert
            }
            return 'done';
        };

        try {
            $driver->execute($key, $op);
        } catch (\RuntimeException) {
        }

        // First attempt fully rolled back: no inbox row, no ledger row.
        Assert::same($this->ledgerCount($key), 0);

        // Retry succeeds and commits exactly one effect.
        Assert::same($driver->execute($key, $op), 'done');
        Assert::same($this->ledgerCount($key), 1);
        Assert::same($attempt, 2);
    }

    // ----------------------------------------------------------------- bootloader wiring

    public function wiresLeaseAndInboxDriversFromConfig(): void
    {
        $leaseKey = $this->key('lease');
        $inboxKey = $this->key('inbox');

        $config = new IdempotencyConfig([
            'default' => 'notifications',
            'storages' => [
                'notifications' => new CycleLeaseConfig(table: 'idempotency'),
                'orders' => new CycleInboxConfig(table: 'inbox'),
            ],
        ]);

        $registry = $this->buildRegistry($config);

        $calls = 0;
        $op = static function (IdempotencyContext $c) use (&$calls): string {
            ++$calls;
            return 'r:' . $c->getKey();
        };

        // Lease (AtLeastOnce) alias: executes once, replays the cache.
        Assert::same($registry->get('notifications')->execute($leaseKey, $op), 'r:' . $leaseKey);
        Assert::same($registry->get('notifications')->execute($leaseKey, $op), 'r:' . $leaseKey);
        // Inbox (ExactlyOnce) alias: dedup + replay.
        Assert::same($registry->get('orders')->execute($inboxKey, $op), 'r:' . $inboxKey);
        Assert::same($registry->get('orders')->execute($inboxKey, $op), 'r:' . $inboxKey);

        Assert::same($calls, 2);
    }

    public function failsFastWhenAliasDeclaresUnbackedGuarantee(): never
    {
        $config = new IdempotencyConfig([
            'storages' => [
                // CycleLease can only provide AtLeastOnce — declaring ExactlyOnce is a misconfig.
                'orders' => new CycleLeaseConfig(guarantee: Guarantee::ExactlyOnce),
            ],
        ]);

        Expect::exception(MisconfigurationException::class)->withMessageContaining('ExactlyOnce');

        $this->buildRegistry($config);
    }

    public function usesContainerBoundSerializerForResultBlob(): void
    {
        $key = $this->key('serializer');

        // A JSON serializer bound in the container must be used by the driver instead of the PhpSerializer
        // default (7c). Proof is two-fold: the round-trip replays the exact value, AND the persisted blob
        // is JSON, not a PHP-serialized string (which would begin with 's:' for a string payload).
        $json = new class implements SerializerInterface {
            public function serialize(mixed $payload): string
            {
                return \json_encode($payload, \JSON_THROW_ON_ERROR);
            }

            public function unserialize(string|\Stringable $payload, string|object|null $type = null): mixed
            {
                return \json_decode((string) $payload, true, 512, \JSON_THROW_ON_ERROR);
            }
        };

        $config = new IdempotencyConfig([
            'storages' => ['notifications' => new CycleLeaseConfig(table: 'idempotency')],
        ]);
        $registry = $this->buildRegistry($config, $json);

        $calls = 0;
        $op = static function () use (&$calls): array {
            ++$calls;
            return ['value' => 'v'];
        };

        Assert::same($registry->get('notifications')->execute($key, $op), ['value' => 'v']);
        Assert::same($registry->get('notifications')->execute($key, $op), ['value' => 'v']); // replay
        Assert::same($calls, 1);

        // The stored blob is JSON produced by the injected serializer, not a PHP-serialized payload.
        $row = $this->db()->select('result')->from('idempotency')->where('key', $key)->run()->fetch();
        Assert::true(\is_array($row));
        Assert::same((string) $row['result'], '{"value":"v"}');
    }

    // ----------------------------------------------------------------- helpers

    private function inboxDriver(): CycleInboxDriver
    {
        $manager = $this->manager();
        $transaction = new TransactionImpl(new ORM(new Factory($manager), new Schema([])), $manager);

        return new CycleInboxDriver(static fn(): TransactionImpl => $transaction, new MutableClock());
    }

    private function buildRegistry(IdempotencyConfig $config, ?SerializerInterface $serializer = null): IdempotencyRegistry
    {
        $manager = $this->manager();

        // The bootloader resolves storage factories through the container, so the Cycle factories
        // inject the database provider / ORM themselves — bind them and hand the container in.
        $container = new Container();
        $container->bindSingleton(DatabaseProviderInterface::class, $manager);
        $container->bindSingleton(ORMInterface::class, new ORM(new Factory($manager), new Schema([])));
        if ($serializer !== null) {
            // The bootloader defers to an application-provided SerializerInterface over its PhpSerializer default.
            $container->bindSingleton(SerializerInterface::class, $serializer);
        }

        return (new IdempotencyBootloader())->initRegistry(
            $config,
            $container,
            $container,
            new MutableClock(),
            new RandomTokenFactory(),
            new DefaultFailureClassifier(),
        );
    }

    private function ledgerCount(string $note): int
    {
        return (int) $this->db()->select()->from('ledger')->where('note', $note)->count();
    }
}
