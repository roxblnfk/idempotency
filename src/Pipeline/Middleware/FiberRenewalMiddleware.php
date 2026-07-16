<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Pipeline\Middleware;

use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\Pipeline\ExecutionCall;
use Spiral\Idempotency\Pipeline\ExecutionMiddleware;

/**
 * Execution middleware that runs the operation inside a {@see \Fiber} so a long-running operation can
 * keep its lease alive cooperatively. Every time the operation calls {@see \Fiber::suspend()} — a "still
 * working" checkpoint — this middleware fires an unforced heartbeat
 * ({@see IdempotencyContext::renew()}, itself rate-limited) before resuming it.
 *
 * The wrap is transparent to an OUTER fiber scheduler: when the whole call is already driven inside a
 * fiber (an async runtime), a suspend from the operation is proxied up. Both directions are forwarded —
 * a resume value comes back down via {@see \Fiber::resume()}, and an exception the scheduler injects
 * into us (e.g. a cancellation via {@see \Fiber::throw()}) is re-injected into the operation at its own
 * suspend point via `$fiber->throw()`, so the inner fiber is never abandoned mid-flight. With no outer
 * fiber, a suspend is a pure heartbeat tick that resumes immediately with `null`.
 *
 * Renewal is cooperative, not preemptive: an operation blocked in a single opaque call (e.g. a slow
 * cURL) never yields and so is never renewed — it must call {@see IdempotencyContext::renew()} or
 * {@see \Fiber::suspend()} at safe points, or finish within its lockTtl. This mirrors an activity
 * heartbeat. Inert for drivers whose {@see IdempotencyContext::renew()} is a no-op (inbox / at-most-once).
 *
 * @api
 */
final readonly class FiberRenewalMiddleware implements ExecutionMiddleware
{
    public function process(ExecutionCall $call, callable $next): mixed
    {
        $fiber = new \Fiber(static fn(): mixed => $next($call));

        $value = $fiber->start();
        while (!$fiber->isTerminated()) {
            // The operation suspended — fire a (throttled) heartbeat checkpoint.
            $call->context->renew(false);

            // No outer scheduler: the suspend was a pure heartbeat tick, resume immediately.
            if (\Fiber::getCurrent() === null) {
                $value = $fiber->resume();
                continue;
            }

            // Stay transparent to the outer scheduler: propagate our inner suspend up, forwarding BOTH a
            // resume value and an exception it injects (e.g. a cancellation) — the latter goes back into
            // the operation at its own suspend point via throw(), so the inner fiber is never abandoned.
            try {
                $resumed = \Fiber::suspend($value);
            } catch (\Throwable $thrown) {
                $value = $fiber->throw($thrown);
                continue;
            }
            $value = $fiber->resume($resumed);
        }

        return $fiber->getReturn();
    }
}
