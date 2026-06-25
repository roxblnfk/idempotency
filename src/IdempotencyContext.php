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
}
