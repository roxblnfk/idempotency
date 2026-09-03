<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Exception;

/**
 * Thrown on replay of a cached domain failure (Domain = exception as a valid outcome). The
 * lease driver stores a lightweight snapshot of the original throwable (class + message) rather than
 * the object itself — serializing arbitrary exceptions is fragile (their trace can capture closures).
 *
 * This is the fallback replay type: the original exception's exact class is NOT reconstructed here. For
 * a faithful, exact-type replay, make the domain exception implement {@see \Spiral\Idempotency\ReplayableFailure}
 * (the driver then rethrows the original type from its payload); otherwise map the failure by
 * {@see $originalClass} in the application exception handler, so an idempotent replay renders the same
 * HTTP status as the first attempt.
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
