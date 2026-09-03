<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Lease;

/**
 * Produces fencing tokens. Monotonicity is not required — a random token is enough,
 * the storage rejects stale owners at CAS time. Abstracted for deterministic testing.
 *
 * @api
 */
interface TokenFactory
{
    /**
     * @return non-empty-string
     */
    public function create(): string;
}
