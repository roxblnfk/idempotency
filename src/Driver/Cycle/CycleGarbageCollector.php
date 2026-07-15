<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle;

use Cycle\Database\DatabaseProviderInterface;
use Psr\Clock\ClockInterface;
use Spiral\Idempotency\Config\IdempotencyConfig;

/**
 * Deletes expired/aged rows from the Cycle-backed idempotency tables. GC is a maintenance sweep the
 * application schedules itself (e.g. a periodic console command or cron job) — this package ships no
 * command and adds no console dependency; all three constructor dependencies are container-autowirable
 * in a Cycle app, so an app resolves this service directly and calls {@see self::collect()}.
 *
 * Per-storage policy ({@see self::collect()} iterates {@see IdempotencyConfig::getStorages()}):
 *
 *  - **Lease** ({@see CycleLeaseConfig}) — always swept. A single DELETE on `expire_time <= now` removes
 *    both dead PROCESSING locks (a crashed worker's abandoned lease) AND expired COMPLETED retention
 *    rows (the lease driver stamps `expire_time = now + retentionTtl` on completion). One indexed
 *    column, one predicate, no opt-in needed — the lease is self-limiting by design.
 *
 *  - **Inbox** ({@see CycleInboxConfig}) / **at-most-once** ({@see CycleAtMostOnceConfig}) — swept ONLY
 *    when the config sets a positive `retentionTtl`; with the default `null` the storage is kept forever
 *    and skipped entirely. These tables have no natural expiry: their whole purpose is to remember that
 *    a key was processed, so deleting a row re-opens the dedup window for that key. Retention is
 *    therefore opt-in and a deliberate tradeoff — see the `retentionTtl` docs on each config. When set,
 *    a DELETE on `create_time <= now - retentionTtl` drops aged rows (index-backed via the `create_time`
 *    index added in {@see CycleSchema::inbox()}).
 *
 *  - **Everything else** (non-Cycle storages, e.g. Redis/Valkey leases) — skipped. Those backends expire
 *    keys server-side (native TTL), so they need no DB sweep here.
 *
 * DELETE affected-row counts are reliable across SQLite/Postgres/MySQL (unlike a no-op UPDATE, which
 * MySQL reports as 0 changed rows), so the returned per-alias counts are exact.
 *
 * @api
 */
final readonly class CycleGarbageCollector
{
    public function __construct(
        private IdempotencyConfig $config,
        private DatabaseProviderInterface $databases,
        private ClockInterface $clock,
    ) {}

    /**
     * Sweep every Cycle-backed storage per the policy above.
     *
     * @return array<non-empty-string, int> alias => rows deleted; only aliases actually swept appear
     *         (a kept-forever inbox/at-most-once storage and every non-Cycle storage are omitted).
     */
    public function collect(): array
    {
        $result = [];

        foreach ($this->config->getStorages() as $alias => $storage) {
            $now = $this->clock->now()->getTimestamp();
            $deleted = match (true) {
                $storage instanceof CycleLeaseConfig => $this->databases
                    ->database($storage->connection)
                    ->delete($storage->table)
                    ->where('expire_time', '<=', $now)
                    ->run(),
                $storage instanceof CycleInboxConfig && $storage->retentionTtl !== null => $this->databases
                    ->database($storage->connection)
                    ->delete($storage->table)
                    ->where('create_time', '<=', $now - $storage->retentionTtl)
                    ->run(),
                $storage instanceof CycleAtMostOnceConfig && $storage->retentionTtl !== null => $this->databases
                    ->database($storage->connection)
                    ->delete($storage->table)
                    ->where('create_time', '<=', $now - $storage->retentionTtl)
                    ->run(),
                default => null,
            };

            if ($deleted !== null) {
                $result[$alias] = $deleted;
            }
        }

        return $result;
    }
}
