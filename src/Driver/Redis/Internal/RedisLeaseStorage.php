<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Redis\Internal;

use Psr\Clock\ClockInterface;
use Spiral\Idempotency\Driver\Redis\RedisCommands;
use Spiral\Idempotency\Lease\LeaseState;
use Spiral\Idempotency\Lease\LeaseStorage;
use Spiral\Idempotency\Lease\StoredEntry;

/**
 * Lease storage over a Redis-compatible server (Redis, Valkey, Dragonfly), reached through the three
 * commands of {@see RedisCommands} so any client library can back it.
 *
 * Data model: one Redis HASH per idempotency key at `{keyPrefix}{key}`, with fields `state`
 * (PROCESSING|COMPLETED), `token` (fencing token), `success` ('1'/'0') and `result` (opaque serialized
 * blob). The lock/retention TTL is carried by a server-side EXPIRE on the key, so an expired record
 * simply vanishes — there is NO explicit "expired takeover" branch as in the SQL driver: a fresh
 * acquire over a vanished key just succeeds. Because EXPIRE always applies, renew() needs no
 * MySQL-style "changed-vs-matched rowCount" workaround either.
 *
 * Every mutation is ONE `EVAL` (a Lua script runs atomically on the server), satisfying the
 * single-roundtrip-CAS contract of {@see LeaseStorage} (owner-check + mutation in one op, no
 * PHP-level get()+set()). Lua `HGET` returns `false` for a missing field/key, so the `~= token`
 * ownership guard also rejects vanished keys.
 *
 * read() is advisory (used ONLY in the conflict branch of acquire), so it is allowed to be 2
 * round-trips (HGETALL + PTTL); this avoids the Lua nil-truncation pitfall of returning a table.
 *
 * @internal Bound to {@see LeaseStorage} per alias by the factory; not public API.
 */
final readonly class RedisLeaseStorage implements LeaseStorage
{
    /**
     * @param non-empty-string $keyPrefix
     */
    public function __construct(
        private RedisCommands $client,
        private ClockInterface $clock,
        private string $keyPrefix = 'idempotency:',
    ) {}

    public function acquire(string $key, string $token, int $lockTtl): bool
    {
        $script = <<<'LUA'
            if redis.call('EXISTS', KEYS[1]) == 1 then return 0 end
            redis.call('HSET', KEYS[1], 'state', 'PROCESSING', 'token', ARGV[1])
            redis.call('EXPIRE', KEYS[1], ARGV[2])
            return 1
            LUA;

        return $this->eval($script, $key, $token, (string) \max(1, $lockTtl));
    }

    public function complete(string $key, string $token, bool $success, mixed $result, int $retentionTtl): bool
    {
        $blob = $this->asBlob($result);

        $script = <<<'LUA'
            if redis.call('HGET', KEYS[1], 'token') ~= ARGV[1] then return 0 end
            redis.call('HSET', KEYS[1], 'state', 'COMPLETED', 'success', ARGV[2])
            redis.call('HDEL', KEYS[1], 'token')
            if ARGV[3] == '1' then redis.call('HSET', KEYS[1], 'result', ARGV[4]) else redis.call('HDEL', KEYS[1], 'result') end
            redis.call('EXPIRE', KEYS[1], ARGV[5])
            return 1
            LUA;

        return $this->eval(
            $script,
            $key,
            $token,
            $success ? '1' : '0',
            $blob === null ? '0' : '1',
            $blob ?? '',
            (string) \max(1, $retentionTtl),
        );
    }

    public function abort(string $key, string $token): bool
    {
        $script = <<<'LUA'
            if redis.call('HGET', KEYS[1], 'token') ~= ARGV[1] then return 0 end
            redis.call('DEL', KEYS[1])
            return 1
            LUA;

        return $this->eval($script, $key, $token);
    }

    public function error(string $key, string $token): bool
    {
        return $this->abort($key, $token);
    }

    public function renew(string $key, string $token, int $lockTtl): bool
    {
        $script = <<<'LUA'
            if redis.call('HGET', KEYS[1], 'token') ~= ARGV[1] then return 0 end
            if redis.call('HGET', KEYS[1], 'state') ~= 'PROCESSING' then return 0 end
            redis.call('EXPIRE', KEYS[1], ARGV[2])
            return 1
            LUA;

        return $this->eval($script, $key, $token, (string) \max(1, $lockTtl));
    }

    public function read(string $key): ?StoredEntry
    {
        /** @var array<string, string> $data */
        $data = $this->client->hgetall($this->prefixed($key));
        if ($data === []) {
            // Key absent or already expired.
            return null;
        }

        $pttl = $this->client->pttl($this->prefixed($key));
        if ($pttl <= 0) {
            // No live TTL: -1 (no expiry set) or -2 (key vanished) — treat as absent.
            return null;
        }

        $token = isset($data['token']) && $data['token'] !== '' ? $data['token'] : null;

        return new StoredEntry(
            key: $key,
            state: LeaseState::from($data['state']),
            token: $token,
            success: isset($data['success']) ? $data['success'] === '1' : null,
            result: $data['result'] ?? null,
            expireTime: $this->clock->now()->add(new \DateInterval('PT' . \max(1, \intdiv($pttl, 1000)) . 'S')),
        );
    }

    /**
     * Runs a Lua CAS script over the single prefixed key and returns whether it applied (1).
     */
    private function eval(string $script, string $key, string ...$args): bool
    {
        $result = $this->client->eval($script, [$this->prefixed($key)], \array_values($args));

        return (int) $result === 1;
    }

    private function prefixed(string $key): string
    {
        return $this->keyPrefix . $key;
    }

    private function asBlob(mixed $result): ?string
    {
        if ($result === null || \is_string($result)) {
            return $result;
        }

        throw new \InvalidArgumentException(\sprintf(
            'Lease storage expects an already-serialized string result (opaque blob), got %s. '
            . 'Serialization must happen above the storage layer.',
            \get_debug_type($result),
        ));
    }
}
