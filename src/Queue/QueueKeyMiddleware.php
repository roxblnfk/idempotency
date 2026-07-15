<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Queue;

use Spiral\Idempotency\Exception\MissingKeyException;
use Spiral\Idempotency\KeyResolverInterface;
use Spiral\Idempotency\Pipeline\IdempotencyCall;
use Spiral\Idempotency\Pipeline\ResolutionMiddleware;
use Spiral\Interceptors\Context\CallContextInterface;

/**
 * Queue resolution middleware: extracts the raw key from the job headers carried in the call context
 * (the `Idempotency-Key` header) and normalizes it via the shared {@see KeyResolverInterface}. The
 * queue analog of {@see \Spiral\Idempotency\Http\HttpKeyMiddleware}.
 *
 * On the consume side, Spiral's queue `Handler` builds the {@see CallContextInterface} as
 * `new CallContext(Target, ['driver', 'queue', 'id', 'payload', 'headers'])` — so the job headers travel
 * as a call ARGUMENT (`getArguments()['headers']`), NOT as a context attribute. They are the PSR-7-like
 * shape `array<string, list<string>>`. No scope binding is needed; add this middleware to the queue
 * pipeline (`transports.queue`) in config instead.
 *
 * The producer sets the header on the outbound job, e.g. `Options::withHeader('Idempotency-Key', ...)`.
 *
 * Pass-through when the key is already resolved (e.g. from the attribute's arg path, which for jobs
 * reads the payload — `key: 'payload.<field>'`) or when the context is not a queue call context
 * (type-guard, so it is inert in a non-queue stack). Uses only `spiral/interceptors` types — never
 * `spiral/queue` — so it carries no hard dependency on the queue package.
 *
 * @api
 */
final readonly class QueueKeyMiddleware implements ResolutionMiddleware
{
    /**
     * @param non-empty-string $header job header carrying the key
     */
    public function __construct(
        private KeyResolverInterface $resolver,
        private string $header = 'Idempotency-Key',
    ) {}

    public function process(IdempotencyCall $call, callable $next): mixed
    {
        if ($call->key !== null) {
            return $next($call);
        }

        // The transport object is the consume call context; without its arguments there are no job
        // headers to read, so stay inert (a non-queue stack).
        if (!$call->context instanceof CallContextInterface) {
            return $next($call);
        }

        $headers = $call->context->getArguments()['headers'] ?? [];
        $raw = \is_array($headers) && isset($headers[$this->header][0]) && \is_scalar($headers[$this->header][0])
            ? (string) $headers[$this->header][0]
            : null;

        if ($raw === null || $raw === '') {
            throw new MissingKeyException(\sprintf(
                'Idempotency key is required: set the "%s" job header (producer side, e.g. Options::withHeader).',
                $this->header,
            ));
        }

        // The scope (operation identity by default) travels on the call; the transport stays agnostic of
        // its format and just hands it to the resolver as the parent key so job types do not collide.
        return $next($call->withKey($this->resolver->resolve($raw, $call->keyScope)));
    }
}
