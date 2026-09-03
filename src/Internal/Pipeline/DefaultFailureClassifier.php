<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Internal\Pipeline;

use Spiral\Idempotency\Pipeline\FailureClassifier;
use Spiral\Idempotency\Pipeline\FailureKind;
use Spiral\Idempotency\Pipeline\Retryable;

/**
 * Default, replaceable classifier:
 *
 *   \Error (and subclasses)            → Infrastructure (retry: a redeploy between attempts may fix it)
 *   \Exception + Retryable    → Infrastructure
 *   \Exception (everything else)       → Domain      ⚠ caches un-tagged infra for the retention TTL
 *
 * Bug is never inferred from a type — it is only an explicit user decision. Pass the set
 * of class-strings that must be treated as Bug, or override this classifier entirely.
 *
 * @internal Bound to {@see FailureClassifier} by the bootloader; not part of the public API.
 */
final class DefaultFailureClassifier implements FailureClassifier
{
    /**
     * @param list<class-string<\Throwable>> $bugExceptions exceptions the user declares unrecoverable
     */
    public function __construct(
        private readonly array $bugExceptions = [],
    ) {}

    public function classify(\Throwable $e): FailureKind
    {
        foreach ($this->bugExceptions as $bug) {
            if ($e instanceof $bug) {
                return FailureKind::Bug;
            }
        }

        if ($e instanceof \Error) {
            return FailureKind::Infrastructure;
        }

        if ($e instanceof Retryable) {
            return FailureKind::Infrastructure;
        }

        return FailureKind::Domain;
    }
}
