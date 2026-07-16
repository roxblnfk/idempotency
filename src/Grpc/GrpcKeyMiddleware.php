<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Grpc;

use Spiral\Idempotency\Exception\MissingKeyException;
use Spiral\Idempotency\KeyResolverInterface;
use Spiral\Idempotency\Pipeline\IdempotencyCall;
use Spiral\Idempotency\Pipeline\ResolutionMiddleware;
use Spiral\Interceptors\Context\CallContextInterface;
use Spiral\RoadRunner\GRPC\ContextInterface;

/**
 * gRPC resolution middleware: extracts the raw key from the call's gRPC metadata (the `idempotency-key`
 * entry) and normalizes it via the shared {@see KeyResolverInterface}. The gRPC analog of
 * {@see \Spiral\Idempotency\Http\HttpKeyMiddleware} / {@see \Spiral\Idempotency\Queue\QueueKeyMiddleware}.
 *
 * On the server side the invoker builds the {@see CallContextInterface} as
 * `new CallContext(Target::fromPair($service, $method), [$grpcContext, $message])` — so the gRPC context
 * (the metadata carrier) travels as the FIRST positional call argument (`getArguments()[0]`), and the
 * decoded request message as the second. Metadata keys are lowercase per HTTP/2, so the lookup is
 * case-insensitive (a client sending `Idempotency-Key` still matches the default `idempotency-key`).
 *
 * Pass-through when the key is already resolved (e.g. from the attribute's arg path) or when the context
 * is not a gRPC call (type-guard, so it is inert in a non-gRPC stack). Uses only `spiral/interceptors`
 * and `spiral/roadrunner-grpc` types, both confined to this transport dir.
 *
 * @api
 */
final readonly class GrpcKeyMiddleware implements ResolutionMiddleware
{
    /**
     * @param non-empty-string $metadataKey gRPC metadata entry carrying the key (lowercase by convention)
     */
    public function __construct(
        private KeyResolverInterface $resolver,
        private string $metadataKey = 'idempotency-key',
    ) {}

    public function process(IdempotencyCall $call, callable $next): mixed
    {
        if ($call->key !== null) {
            return $next($call);
        }

        $ctx = $this->grpcContext($call->context);
        if ($ctx === null) {
            return $next($call);
        }

        $raw = $this->extract($ctx);
        if ($raw === null || $raw === '') {
            throw new MissingKeyException(\sprintf(
                'Idempotency key is required: send the "%s" gRPC metadata entry (client side).',
                $this->metadataKey,
            ));
        }

        // The scope (operation identity by default) travels on the call; the transport stays agnostic of
        // its format and just hands it to the resolver as the parent key so methods do not collide.
        return $next($call->withKey($this->resolver->resolve($raw, $call->keyScope)));
    }

    private function extract(ContextInterface $ctx): ?string
    {
        // gRPC metadata keys are lowercase per HTTP/2; match case-insensitively. Metadata values are a
        // list of strings; the context also carries non-metadata entries (keyed by class-name), so guard.
        foreach ($ctx->getValues() as $name => $values) {
            if (\strcasecmp($name, $this->metadataKey) !== 0) {
                continue;
            }
            $value = \is_array($values) ? ($values[0] ?? null) : $values;

            return \is_scalar($value) ? (string) $value : null;
        }

        return null;
    }

    private function grpcContext(mixed $context): ?ContextInterface
    {
        // The gRPC context (metadata carrier) is the first positional call argument. Accept a bare
        // ContextInterface too (defensive); anything else means a non-gRPC stack -> stay inert.
        if ($context instanceof CallContextInterface) {
            $first = $context->getArguments()[0] ?? null;

            return $first instanceof ContextInterface ? $first : null;
        }

        return $context instanceof ContextInterface ? $context : null;
    }
}
