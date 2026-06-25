<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Internal\Lease;

use Psr\Clock\ClockInterface;
use Spiral\Idempotency\Exception\LeaseLostException;
use Spiral\Idempotency\Lease\Acquired;
use Spiral\Idempotency\Lease\AcquireResult;
use Spiral\Idempotency\Lease\AcquireRetry;
use Spiral\Idempotency\Lease\AlreadyCompleted;
use Spiral\Idempotency\Lease\LeaseManagerInterface;
use Spiral\Idempotency\Lease\LeaseState;
use Spiral\Idempotency\Lease\LeaseStorageInterface;
use Spiral\Idempotency\Lease\Locked;
use Spiral\Idempotency\Lease\TokenFactoryInterface;

/**
 * Default {@see LeaseManagerInterface}: drives the lease state machine over a
 * {@see LeaseStorageInterface}, mapping the atomic conditional insert and the conflict-branch read
 * into the discriminated {@see AcquireResult}.
 *
 * @internal Bound to {@see LeaseManagerInterface} by the bootloader; not part of the public API.
 */
final class LeaseManager implements LeaseManagerInterface
{
    public function __construct(
        private readonly LeaseStorageInterface $storage,
        private readonly ClockInterface $clock,
        private readonly TokenFactoryInterface $tokens = new RandomTokenFactory(),
    ) {}

    public function acquire(string $key, int $lockTtl): AcquireResult
    {
        $token = $this->tokens->create();

        if ($this->storage->acquire($key, $token, $lockTtl)) {
            // Main path: the conditional insert succeeded — not a single read.
            return new Acquired($key, $token);
        }

        // Conflict branch — the only place a read() is needed.
        $entry = $this->storage->read($key);
        if ($entry === null) {
            // Vanished between insert and read (lock TTL expired in the gap).
            return new AcquireRetry();
        }

        if ($entry->state === LeaseState::Completed) {
            return new AlreadyCompleted($key, $entry->success ?? false, $entry->result);
        }

        $now = $this->clock->now()->getTimestamp();
        $retryAfter = \max(0, $entry->expireTime->getTimestamp() - $now);

        return new Locked($key, $retryAfter, $entry->expireTime);
    }

    public function complete(string $key, string $token, bool $success, mixed $result, int $retentionTtl): void
    {
        if (!$this->storage->complete($key, $token, $success, $result, $retentionTtl)) {
            throw $this->lost($key, 'complete');
        }
    }

    public function abort(string $key, string $token): void
    {
        if (!$this->storage->abort($key, $token)) {
            throw $this->lost($key, 'abort');
        }
    }

    public function error(string $key, string $token): void
    {
        if (!$this->storage->error($key, $token)) {
            throw $this->lost($key, 'error');
        }
    }

    public function renew(string $key, string $token, int $lockTtl): void
    {
        if (!$this->storage->renew($key, $token, $lockTtl)) {
            throw $this->lost($key, 'renew');
        }
    }

    private function lost(string $key, string $op): LeaseLostException
    {
        return new LeaseLostException(\sprintf(
            'Lease for key "%s" was lost before %s() (CAS rejected: not the owner).',
            $key,
            $op,
        ));
    }
}
