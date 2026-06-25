<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle\Internal;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Query\InsertQuery;
use Cycle\Database\Query\OnConflict;
use Cycle\Database\Query\QueryParameters;
use Psr\Clock\ClockInterface;
use Spiral\Idempotency\Lease\LeaseState;
use Spiral\Idempotency\Lease\LeaseStorageInterface;
use Spiral\Idempotency\Lease\StoredEntry;

/**
 * Lease storage over a Cycle DBAL connection. acquire() is an atomic
 * `INSERT ... ON CONFLICT (key) DO NOTHING` whose affected-row count distinguishes inserted vs
 * conflict (more portable than RETURNING/lastInsertID across PG and SQLite);
 * a dead (expired) record is taken over by a conditional UPDATE. All mutations are single-statement
 * CAS by token.
 *
 * `expire_time` is stored as an epoch integer (not a TIMESTAMP) so TTL comparisons are timezone-free
 * and portable; the column is still indexable for GC. Timestamp columns follow the
 * AIP-142 `*_time` naming convention.
 *
 * @internal Bound to {@see LeaseStorageInterface} per alias by the bootloader; not public API.
 */
final class CycleLeaseStorage implements LeaseStorageInterface
{
    /**
     * @param non-empty-string $table
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly ClockInterface $clock,
        private readonly string $table = 'idempotency',
    ) {}

    public function acquire(string $key, string $token, int $lockTtl): bool
    {
        $now = $this->now();
        $expireTime = $now + \max(1, $lockTtl);

        $insert = $this->db->insert($this->table)
            ->columns('key', 'state', 'token', 'success', 'result', 'expire_time', 'create_time')
            ->values([$key, LeaseState::Processing->value, $token, null, null, $expireTime, $now])
            ->onConflict(OnConflict::target('key')->doNothing());

        if ($this->runInsert($insert) === 1) {
            return true;
        }

        // Conflict: take over only if the existing record is dead (expired).
        $taken = $this->db->update(
            $this->table,
            [
                'state' => LeaseState::Processing->value,
                'token' => $token,
                'success' => null,
                'result' => null,
                'expire_time' => $expireTime,
                'create_time' => $now,
            ],
            ['key' => $key],
        )->where('expire_time', '<=', $now)->run();

        return $taken === 1;
    }

    public function complete(string $key, string $token, bool $success, mixed $result, int $retentionTtl): bool
    {
        $affected = $this->db->update(
            $this->table,
            [
                'state' => LeaseState::Completed->value,
                'token' => null,
                'success' => $success,
                'result' => $this->asBlob($result),
                'expire_time' => $this->now() + \max(1, $retentionTtl),
            ],
            ['key' => $key, 'token' => $token],
        )->run();

        return $affected === 1;
    }

    public function abort(string $key, string $token): bool
    {
        return $this->db->delete($this->table, ['key' => $key, 'token' => $token])->run() === 1;
    }

    public function error(string $key, string $token): bool
    {
        return $this->abort($key, $token);
    }

    public function renew(string $key, string $token, int $lockTtl): bool
    {
        $affected = $this->db->update(
            $this->table,
            ['expire_time' => $this->now() + \max(1, $lockTtl)],
            ['key' => $key, 'token' => $token, 'state' => LeaseState::Processing->value],
        )->run();

        return $affected === 1;
    }

    public function read(string $key): ?StoredEntry
    {
        $row = $this->db->select()->from($this->table)->where('key', $key)->run()->fetch();
        if (!\is_array($row)) {
            return null;
        }

        $expireTime = (int) $row['expire_time'];
        if ($expireTime <= $this->now()) {
            // Expired record is treated as absent.
            return null;
        }

        $rowKey = (string) $row['key'];
        if ($rowKey === '') {
            return null;
        }

        $tokenStr = $row['token'] === null ? null : (string) $row['token'];
        $token = $tokenStr === '' ? null : $tokenStr;

        return new StoredEntry(
            key: $rowKey,
            state: LeaseState::from((string) $row['state']),
            token: $token,
            success: $row['success'] !== null ? (bool) $row['success'] : null,
            result: $row['result'] !== null ? (string) $row['result'] : null,
            expireTime: (new \DateTimeImmutable())->setTimestamp($expireTime),
        );
    }

    private function runInsert(InsertQuery $insert): int
    {
        $params = new QueryParameters();
        // sqlStatement() is the documented compile entrypoint; we need the affected-row count
        // (not lastInsertID) to tell inserted from conflict — see class docblock.
        /** @psalm-suppress InternalMethod */
        $sql = $insert->sqlStatement($params);

        return $this->db->execute($sql, $params->getParameters());
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private function asBlob(mixed $result): ?string
    {
        if ($result === null || \is_string($result)) {
            return $result;
        }

        throw new \InvalidArgumentException(\sprintf(
            'Lease storage expects an already-serialized string result (opaque blob), got %s.',
            \get_debug_type($result),
        ));
    }
}
