<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Internal\Lease;

use Spiral\Idempotency\IdempotencyContext;

/**
 * Context handed to the operation closure by the lease (AtLeastOnce) driver. The lease side-effect is
 * non-transactional by definition, so there is nothing transport-specific to expose beyond the key.
 *
 * @internal Created by the driver; users only ever see the {@see IdempotencyContext} contract.
 */
final readonly class LeaseContext implements IdempotencyContext
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
