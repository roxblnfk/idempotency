<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Exception;

use Spiral\Idempotency\Lease\Locked;

/**
 * Raised by a high-level driver when acquire() reports the key is currently held by another in-flight
 * execution (PROCESSING). The transport middleware maps this to 409 / gRPC ABORTED / Job-retry
 *.
 *
 * @api
 */
final class LockedException extends IdempotencyException
{
    public function __construct(
        public readonly Locked $lock,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('Key "%s" is currently being processed; retry after %d s.', $lock->key, $lock->retryAfter),
            0,
            $previous,
        );
    }
}
