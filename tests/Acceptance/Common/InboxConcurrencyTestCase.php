<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Acceptance\Common;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Exception\StatementException;
use Spiral\Idempotency\Driver\Cycle\Internal\CycleInboxDriver;
use Testo\Assert;
use Testo\Codecov\Covers;

/**
 * The ExactlyOnce serialization guarantee, proven deterministically instead of by racing processes:
 * two independent connections contend for one inbox key, and a bounded `lock_timeout` turns "the
 * loser waits for the winner" into an assertable outcome (SQLSTATE `55P03`) rather than a sleep.
 *
 * These tests drive the raw SQL the {@see CycleInboxDriver} relies on (`INSERT ... ON CONFLICT (key)
 * DO NOTHING`) over two sessions — the driver itself is single-connection, so it cannot exhibit the
 * cross-connection blocking this class asserts. Postgres only: the `SET lock_timeout` / `55P03`
 * mechanics are dialect-specific and there is nothing to prove on the in-memory SQLite driver.
 *
 * @see CycleInboxDriver the production consumer of this INSERT shape
 */
#[Covers(CycleInboxDriver::class)]
abstract class InboxConcurrencyTestCase extends DatabaseTestCase
{
    public function concurrentWriterBlocksThenDeduplicates(): void
    {
        $key = $this->key('inbox-concurrency');
        $a = $this->db();
        $b = $this->secondConnection();

        $a->begin();
        try {
            // Connection A is the first writer in flight: its INSERT holds the key's row, uncommitted.
            Assert::same($this->insertInbox($a, $key), 1);

            // Connection B, with a short lock_timeout, hits the same key. It MUST block on A's row lock
            // and then fail by lock timeout (SQLSTATE 55P03) — the serialization point made visible:
            // the concurrent writer physically waits on the first transaction's outcome.
            $b->execute("SET lock_timeout = '200ms'");
            try {
                $this->insertInbox($b, $key);
                Assert::fail('the concurrent writer must not proceed while the first holds the row');
            } catch (StatementException $e) {
                Assert::string($e->getMessage())->contains('55P03');
            }

            $a->commit();
        } catch (\Throwable $e) {
            $a->rollback();
            throw $e;
        }

        // A committed the key. B retries the same INSERT and now sees the committed row → ON CONFLICT
        // DO NOTHING → 0 affected: the dedup branch. Exactly one row exists across both writers.
        Assert::same($this->insertInbox($b, $key), 0);
    }

    public function rolledBackWriterLeavesKeyFreeForCleanRetry(): void
    {
        $key = $this->key('inbox-concurrency');
        $a = $this->db();
        $b = $this->secondConnection();

        // Connection A crashes before COMMIT: its INSERT is rolled back, so the key never existed.
        $a->begin();
        try {
            Assert::same($this->insertInbox($a, $key), 1);
        } finally {
            $a->rollback();
        }

        // Connection B is a clean retry after that crash: no conflicting row remains, so the INSERT is
        // a fresh success (1 affected) rather than a dedup skip.
        Assert::same($this->insertInbox($b, $key), 1);
    }

    /**
     * An independent second connection to the same database — a distinct session (and PDO), so its
     * transaction and lock waits are real rather than shared with {@see DatabaseTestCase::db()}. The
     * driver-specific subclass builds it, because only it knows which connection config to use.
     */
    abstract protected function secondConnection(): DatabaseInterface;

    /**
     * The dedup INSERT the inbox driver relies on: `INSERT ... ON CONFLICT (key) DO NOTHING`, returning
     * the affected-row count (1 = fresh insert, 0 = duplicate skipped). Raw SQL keeps each connection's
     * statement explicit; the driver compiles the same shape through the query builder.
     *
     * @param non-empty-string $key
     */
    private function insertInbox(DatabaseInterface $db, string $key): int
    {
        return $db->execute(
            'INSERT INTO inbox ("key", create_time, result) VALUES (?, ?, NULL) ON CONFLICT ("key") DO NOTHING',
            [$key, \time()],
        );
    }
}
