<?php

declare(strict_types=1);

namespace Spiral\Idempotency;

/**
 * Single facade over both branches (lease/inbox). The driver behind a storage alias decides the
 * guarantee and how {@see $operation} is wrapped:
 *
 *  - lease/external driver (AtLeastOnce) wraps the operation in lease + CAS;
 *  - inbox driver (ExactlyOnce) runs it inside a DB transaction with the connection bound in
 *    the context.
 *
 * @api
 */
interface IdempotencyInterface
{
    /**
     * Run {@see $operation} idempotently under {@see $key}. On replay the cached result is returned
     * instead of executing the operation again.
     *
     * @template T
     * @param non-empty-string $key
     * @param \Closure(IdempotencyContext): T $operation
     * @param ExecuteOptions|null $options per-call TTL overrides; null fields fall back to the storage's
     *        configured defaults
     * @return T
     */
    public function execute(string $key, \Closure $operation, ?ExecuteOptions $options = null): mixed;
}
