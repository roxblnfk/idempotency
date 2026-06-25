<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Lease;

/**
 * The operation already reached a terminal COMPLETED state — replay the cached outcome.
 *
 * @api
 */
final class AlreadyCompleted implements AcquireResult
{
    /**
     * @param non-empty-string $key
     * @param bool $success domain outcome: true = success, false = domain failure
     * @param mixed $result opaque cached payload (the serialized blob stored at complete()), or null
     */
    public function __construct(
        public readonly string $key,
        public readonly bool $success,
        public readonly mixed $result,
    ) {}
}
