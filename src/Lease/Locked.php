<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Lease;

/**
 * Someone holds PROCESSING right now. The transport middleware maps this to
 * 409 / gRPC ABORTED / Job-retry and may surface {@see $retryAfter} as a `Retry-After` header.
 *
 * @api
 */
final class Locked implements AcquireResult
{
    /**
     * @param non-empty-string $key
     * @param int<0, max> $retryAfter suggested seconds until the lock is expected to expire
     * @param \DateTimeImmutable $expireTime when the current holder's lock expires (AIP-142 naming)
     */
    public function __construct(
        public readonly string $key,
        public readonly int $retryAfter,
        public readonly \DateTimeImmutable $expireTime,
    ) {}
}
