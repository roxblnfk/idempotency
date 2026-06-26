<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Pipeline;

/**
 * Universal middleware orchestrator (mirroring Testo's `Pipeline`): one engine drives both the
 * resolution and the execution pipelines. Middleware are run as a matryoshka — the first in the list
 * is the OUTERMOST, its `try/finally` wraps every inner one and the terminal; a middleware that does
 * not call `$next` short-circuits the rest of the stack.
 *
 * Reusable: {@see self::process()} resets the position per run, so one instance can be invoked many
 * times.
 *
 * @template TCall of object
 *
 * @api
 */
final class Pipeline
{
    /** @var list<Middleware> */
    private readonly array $middleware;

    private int $position = 0;

    /** @var \Closure(TCall): mixed */
    private \Closure $terminal;

    public function __construct(Middleware ...$middleware)
    {
        $this->middleware = \array_values($middleware);
    }

    /**
     * Run {@see $call} through the middleware stack, ending at {@see $terminal}.
     *
     * @param TCall $call
     * @param \Closure(TCall): mixed $terminal
     */
    public function process(object $call, \Closure $terminal): mixed
    {
        $run = clone $this;
        $run->position = 0;
        $run->terminal = $terminal;

        return $run->handle($call);
    }

    /**
     * @param TCall $call
     */
    private function handle(object $call): mixed
    {
        $middleware = $this->middleware[$this->position] ?? null;

        if ($middleware === null) {
            return ($this->terminal)($call);
        }

        $next = $this->next();

        // The marker has no process(); the concrete Resolution/Execution middleware do. The closure
        // re-enters this same engine at the next position.
        /** @psalm-suppress UndefinedInterfaceMethod */
        return $middleware->process($call, static fn(object $c): mixed => $next->handle($c));
    }

    private function next(): self
    {
        $clone = clone $this;
        ++$clone->position;

        return $clone;
    }
}
