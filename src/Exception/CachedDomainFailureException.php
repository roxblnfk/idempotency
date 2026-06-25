<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Exception;

/**
 * Thrown on replay of a cached domain failure (Domain = exception as a valid outcome). The
 * lease driver stores a lightweight snapshot of the original throwable (class + message) rather than
 * the object itself — serializing arbitrary exceptions is fragile (their trace can capture closures).
 *
 * The exact-type replay of the original exception is the job of the transport middleware (which caches
 * the full response), not of this default driver.
 *
 * @api
 */
final class CachedDomainFailureException extends IdempotencyException
{
    public function __construct(
        public readonly string $originalClass,
        string $message,
    ) {
        parent::__construct($message);
    }
}
