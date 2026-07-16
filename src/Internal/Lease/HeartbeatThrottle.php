<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Internal\Lease;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Spiral\Idempotency\Exception\LeaseLostException;

/**
 * Per-execution, stateful heartbeat throttle sitting behind the lease context's
 * {@see \Spiral\Idempotency\IdempotencyContext::renew()} callable. It keeps a long-running PROCESSING
 * lock alive by invoking the underlying renew action, but rate-limits unforced heartbeats so a chatty
 * operation cannot hammer the storage/transport: an unforced beat is dropped unless at least
 * `threshold * lockTtl` seconds have elapsed since the last actual renew. A forced beat
 * (`$force = true`) always renews and resets the window.
 *
 * Losing the lease mid-flight (the lock TTL already expired and the key was re-acquired) is downgraded
 * to a warning — consistent with the terminal transition ({@see LeaseIdempotency}) — and disables
 * further beats: renewing a lock we no longer own cannot help.
 *
 * @internal Built per acquire by {@see LeaseIdempotency}; exposed to the operation only as the
 *           {@see \Spiral\Idempotency\IdempotencyContext::renew()} callable, never referenced directly.
 */
final class HeartbeatThrottle
{
    private float $lastAt;

    private bool $lost = false;

    /**
     * @param \Closure(): void $renew underlying renew action (extends PROCESSING by lockTtl, CAS by token)
     * @param int<1, max> $lockTtl PROCESSING lock TTL, seconds
     * @param float $threshold fraction of lockTtl an unforced beat must wait before it renews again (0..1)
     * @param non-empty-string $key for diagnostics only
     */
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly \Closure $renew,
        private readonly int $lockTtl,
        private readonly float $threshold,
        private readonly string $key,
        private readonly ?LoggerInterface $logger = null,
    ) {
        // Anchor the throttle window at the acquire moment, so the first unforced beat is rate-limited
        // relative to acquire rather than firing a redundant renew right after the lock was taken.
        $this->lastAt = $this->now();
    }

    public function __invoke(bool $force = false): void
    {
        if ($this->lost) {
            return;
        }

        $now = $this->now();
        if (!$force && ($now - $this->lastAt) < ((float) $this->lockTtl * $this->threshold)) {
            return; // throttled: too soon since the last renew
        }

        try {
            ($this->renew)();
            $this->lastAt = $now;
        } catch (LeaseLostException $e) {
            $this->lost = true;
            $this->logger?->warning(
                'Idempotency lease for key "{key}" was lost during a heartbeat; the operation continues '
                . 'but its outcome will not be cached. Consider a longer lockTtl.',
                ['key' => $this->key, 'exception' => $e],
            );
        }
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
