<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle\Internal;

use Spiral\Idempotency\IdempotencyContext;

/**
 * Context handed to the operation closure by the at-most-once (dedup-guard) driver. The effect runs
 * fire-and-forget, outside any transaction the driver controls, so there is nothing transport-specific
 * to expose beyond the key — like {@see \Spiral\Idempotency\Internal\Lease\LeaseContext}.
 *
 * @internal Created by the driver; users only ever see the {@see IdempotencyContext} contract.
 */
final readonly class AtMostOnceContext implements IdempotencyContext
{
    /**
     * @param non-empty-string $key
     */
    public function __construct(
        private string $key,
    ) {}

    public function getKey(): string
    {
        return $this->key;
    }
}
