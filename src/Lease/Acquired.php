<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Lease;

/**
 * The key was free and is now held by us. The {@see $token} is the fencing token and
 * MUST be passed back into every mutation (complete/abort/error/renew).
 *
 * @api
 */
final class Acquired implements AcquireResult
{
    /**
     * @param non-empty-string $key
     * @param non-empty-string $token fencing token, returned into all mutations
     */
    public function __construct(
        public readonly string $key,
        public readonly string $token,
    ) {}
}
