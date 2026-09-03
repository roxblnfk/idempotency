<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Internal\Lease\Storage;

use Psr\Clock\ClockInterface;
use Spiral\Idempotency\Lease\LeaseState;
use Spiral\Idempotency\Lease\LeaseStorage;
use Spiral\Idempotency\Lease\StoredEntry;

/**
 * In-process {@see LeaseStorage} — single-process, for tests and single-worker setups.
 * TTL expiry is computed against the injected {@see ClockInterface}; an expired record is treated as
 * absent (so acquire() over it succeeds), mirroring Redis key expiry.
 *
 * It is NOT concurrency-safe across processes — that is the job of the Redis/DB backends.
 *
 * @internal Implementation detail / test aid; not part of the public API. May change at any time.
 */
final class InMemoryLeaseStorage implements LeaseStorage
{
    /** @var array<string, StoredEntry> */
    private array $entries = [];

    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    public function acquire(string $key, string $token, int $lockTtl): bool
    {
        if ($this->live($key) !== null) {
            return false;
        }

        $this->entries[$key] = new StoredEntry(
            key: $key,
            state: LeaseState::Processing,
            token: $token,
            success: null,
            result: null,
            expireTime: $this->expiry($lockTtl),
        );

        return true;
    }

    public function complete(string $key, string $token, bool $success, mixed $result, int $retentionTtl): bool
    {
        $entry = $this->owned($key, $token);
        if ($entry === null) {
            return false;
        }

        $this->entries[$key] = new StoredEntry(
            key: $key,
            state: LeaseState::Completed,
            token: null,
            success: $success,
            result: $this->asBlob($result),
            expireTime: $this->expiry($retentionTtl),
        );

        return true;
    }

    public function abort(string $key, string $token): bool
    {
        if ($this->owned($key, $token) === null) {
            return false;
        }

        unset($this->entries[$key]);
        return true;
    }

    public function error(string $key, string $token): bool
    {
        return $this->abort($key, $token);
    }

    public function renew(string $key, string $token, int $lockTtl): bool
    {
        $entry = $this->owned($key, $token);
        if ($entry === null || $entry->state !== LeaseState::Processing) {
            return false;
        }

        $this->entries[$key] = new StoredEntry(
            key: $key,
            state: LeaseState::Processing,
            token: $token,
            success: null,
            result: null,
            expireTime: $this->expiry($lockTtl),
        );

        return true;
    }

    public function read(string $key): ?StoredEntry
    {
        return $this->live($key);
    }

    /**
     * Returns the record if present and not expired; prunes it otherwise.
     */
    private function live(string $key): ?StoredEntry
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return null;
        }

        if ($entry->expireTime->getTimestamp() <= $this->clock->now()->getTimestamp()) {
            unset($this->entries[$key]);
            return null;
        }

        return $entry;
    }

    private function owned(string $key, string $token): ?StoredEntry
    {
        $entry = $this->live($key);
        return $entry !== null && $entry->token === $token ? $entry : null;
    }

    private function expiry(int $ttl): \DateTimeImmutable
    {
        return $this->clock->now()->add(new \DateInterval('PT' . \max(1, $ttl) . 'S'));
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
