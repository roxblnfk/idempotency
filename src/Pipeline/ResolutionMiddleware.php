<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Pipeline;

/**
 * A middleware of the resolution (outer) pipeline — the transport-facing phase that turns an inbound
 * call into a resolved {@see IdempotencyCall} before the driver handler takes over: extract and
 * normalize the key, map the outcome back to the transport (e.g. Locked → 409 / job retry).
 *
 * Named by its phase of application, not by its context type. Its sibling, the execution (inner)
 * pipeline, has its own middleware contract over a different context.
 *
 * Composition is the matryoshka `$next` pattern:
 *  - wrap the rest (`return $next($call)` after/around work);
 *  - short-circuit (return without calling `$next`), e.g. a cached replay;
 *  - enrich (`$next($call->withKey(...))`), e.g. resolve the key.
 *
 * Type-guard / pass-through: a middleware that does not apply to the current
 * {@see IdempotencyCall::$context} type (e.g. an HTTP middleware in a Queue stack) MUST
 * `return $next($call)` untouched.
 *
 * @api
 */
interface ResolutionMiddleware extends Middleware
{
    /**
     * @param callable(IdempotencyCall): mixed $next
     */
    public function process(IdempotencyCall $call, callable $next): mixed;
}
