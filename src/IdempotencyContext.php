<?php

declare(strict_types=1);

namespace Spiral\Idempotency;

/**
 * Execution context handed to the operation closure inside an idempotent run.
 *
 * The base contract is transport- and driver-agnostic: it only exposes the resolved key. Drivers that
 * need to share more with the operation extend this with their own contract — e.g. the Cycle inbox
 * driver's {@see \Spiral\Idempotency\Driver\Cycle\CycleContext} adds the transactional connection the
 * side-effect must be written through. Check for that capability with `instanceof`.
 *
 * @api
 */
interface IdempotencyContext
{
    /**
     * Resolved idempotency key of the current operation.
     *
     * @return non-empty-string
     */
    public function getKey(): string;

    /**
     * Keep a long-running operation's PROCESSING lock alive (AtLeastOnce lease). Call it at safe points
     * inside a long operation: a lease-backed context renews the lock, while drivers without a renewable
     * lock (inbox owns a DB transaction; at-most-once is fire-once) treat it as a no-op — so generic
     * operation code can call it regardless of the backing storage.
     *
     * Unforced calls (`$force = false`) are rate-limited (see the lease `heartbeatThreshold`) so a chatty
     * operation cannot hammer the storage; pass `$force = true` to renew unconditionally, e.g. right
     * after a long blocking step. Never throws — a lease lost mid-flight is downgraded to a warning.
     *
     * Renewal is cooperative, not preemptive: an operation blocked in a single opaque call must reach a
     * renew() / Fiber::suspend() checkpoint or finish within its lockTtl (mirrors an activity heartbeat).
     */
    public function renew(bool $force = false): void;
}
