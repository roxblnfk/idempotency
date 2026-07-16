<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Internal\Lease;

use Spiral\Idempotency\IdempotencyContext;

/**
 * Context handed to the operation closure by the lease (AtLeastOnce) driver. The lease side-effect is
 * non-transactional by definition, so beyond the key it exposes only the heartbeat callable that keeps a
 * long-running PROCESSING lock alive (backed by a {@see HeartbeatThrottle}).
 *
 * @internal Created by the driver; users only ever see the {@see IdempotencyContext} contract.
 */
final readonly class LeaseContext implements IdempotencyContext
{
    /**
     * @param non-empty-string $key
     * @param \Closure(bool): void $renew throttled heartbeat callable (see {@see HeartbeatThrottle})
     */
    public function __construct(
        private string $key,
        private \Closure $renew,
    ) {}

    public function getKey(): string
    {
        return $this->key;
    }

    public function renew(bool $force = false): void
    {
        ($this->renew)($force);
    }
}
