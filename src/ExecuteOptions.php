<?php

declare(strict_types=1);

namespace Spiral\Idempotency;

/**
 * Optional per-call overrides for {@see Idempotency::execute()}. Each field falls back to the
 * storage's configured default when null — typically sourced from an {@see Attribute\Idempotent}
 * attribute by the interceptor.
 *
 * @api
 */
final readonly class ExecuteOptions
{
    /**
     * @param int<1, max>|null $lockTtl PROCESSING lock TTL, seconds. Applies to the lease/AtLeastOnce
     *        driver only; the inbox/ExactlyOnce driver ignores it (mutual exclusion there is the row
     *        lock of the in-progress INSERT, not a time-bound lease).
     * @param int<1, max>|null $ttl retention TTL of the completed record, seconds.
     */
    public function __construct(
        public ?int $lockTtl = null,
        public ?int $ttl = null,
    ) {}
}
