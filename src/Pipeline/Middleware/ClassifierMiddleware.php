<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Pipeline\Middleware;

use Spiral\Idempotency\Exception\ClassifiedException;
use Spiral\Idempotency\Pipeline\ExecutionCall;
use Spiral\Idempotency\Pipeline\ExecutionMiddleware;
use Spiral\Idempotency\Pipeline\FailureClassifierInterface;

/**
 * Execution middleware: catches a throwable from the operation, classifies it into a {@see FailureKind}
 * via the {@see FailureClassifierInterface}, and rethrows it wrapped in a {@see ClassifiedException} so
 * the driver handler can pick the terminal transition (complete(false) / abort / error). Successful
 * results pass through untouched.
 *
 * An already-{@see ClassifiedException} is rethrown as-is — an inner middleware already decided, and
 * reclassification must be idempotent.
 *
 * @api
 */
final readonly class ClassifierMiddleware implements ExecutionMiddleware
{
    public function __construct(
        private FailureClassifierInterface $classifier,
    ) {}

    public function process(ExecutionCall $call, callable $next): mixed
    {
        try {
            return $next($call);
        } catch (ClassifiedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ClassifiedException($this->classifier->classify($e), $e);
        }
    }
}
