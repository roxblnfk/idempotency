<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Pipeline;

/**
 * A middleware of the execution (inner) pipeline — the domain phase that wraps the operation once the
 * key is resolved and the lease/inbox is held: classify the outcome, apply the retry policy, renew a
 * long-running lease, then invoke the operation.
 *
 * Named by its phase of application, not by its context type. Sibling of {@see ResolutionMiddleware}
 * (the resolution pipeline), over the transport-agnostic {@see ExecutionCall} context.
 *
 * Same matryoshka `$next` composition: wrap, short-circuit or pass through.
 *
 * @api
 */
interface ExecutionMiddleware extends Middleware
{
    /**
     * @param callable(ExecutionCall): mixed $next
     */
    public function process(ExecutionCall $call, callable $next): mixed;
}
