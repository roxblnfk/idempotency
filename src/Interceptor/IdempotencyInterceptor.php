<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Interceptor;

use Psr\Container\ContainerInterface;
use Spiral\Core\BinderInterface;
use Spiral\Core\ContainerScope;
use Spiral\Idempotency\Attribute\Idempotent;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\KeyResolverInterface;
use Spiral\Idempotency\KeySourceInterface;
use Spiral\Idempotency\ResultCodecInterface;
use Spiral\Interceptors\Context\CallContextInterface;
use Spiral\Interceptors\HandlerInterface;
use Spiral\Interceptors\InterceptorInterface;

/**
 * Makes controller/handler actions idempotent declaratively via {@see Idempotent}.
 *
 * The interceptor is transport-agnostic: it reads the attribute, resolves a key, and runs the action
 * through the named storage, replaying a cached result instead of re-running on a repeat. Everything
 * transport-specific is resolved from the ACTIVE scope's container at call time (no proxy needed):
 *
 *  - {@see KeySourceInterface} extracts raw key material from the endpoint context (HTTP: the request
 *    header/field; queue: the payload; ...);
 *  - {@see ResultCodecInterface} marshals the result to/from the cache and decorates it with
 *    idempotency metadata (HTTP: a PSR-7 response snapshot + `Idempotency-*` headers).
 *
 * Bind those two per scope (see {@see \Spiral\Idempotency\Bootloader\HttpIdempotencyBootloader} for
 * HTTP) and this class needs no transport details at all.
 *
 * Best fit for the AtLeastOnce/lease storage: the action's side-effect is non-transactional and the
 * result is what we cache. The ExactlyOnce/inbox storage needs the side-effect written through the
 * bound {@see IdempotencyContext} transaction, which this interceptor does not propagate into the
 * action — use the registry directly there.
 *
 * @api
 */
final class IdempotencyInterceptor implements InterceptorInterface
{
    public function __construct(
        private readonly IdempotencyRegistry $registry,
        private readonly KeyResolverInterface $keys,
        private readonly ContainerInterface $container,
    ) {}

    public function intercept(CallContextInterface $context, HandlerInterface $handler): mixed
    {
        $attribute = $this->attribute($context->getTarget()->getReflection());

        if ($attribute === null) {
            return $handler->handle($context);
        }

        $codec = $this->scoped(ResultCodecInterface::class);
        $key = $this->keys->resolve($this->material($attribute, $context));

        $executed = false;
        $cached = $this->registry->get($attribute->storage)->execute(
            $key,
            function (IdempotencyContext $operation) use (&$executed, $handler, $context, $codec): mixed {
                $executed = true;

                // Expose the driver's context (e.g. a CycleContext with the transactional connection)
                // so the action can inject IdempotencyContext and narrow to it.
                return $codec->encode($this->dispatch($operation, $handler, $context));
            },
            new ExecuteOptions($attribute->lockTtl, $attribute->ttl),
        );

        return $codec->decorate($codec->decode($cached), $key, replayed: !$executed);
    }

    /**
     * Run the action with the active {@see IdempotencyContext} bound, so it can be injected into the
     * controller (and narrowed to a driver contract such as
     * {@see \Spiral\Idempotency\Driver\Cycle\CycleContext}). The binding is scoped to this call.
     */
    private function dispatch(
        IdempotencyContext $operation,
        HandlerInterface $handler,
        CallContextInterface $context,
    ): mixed {
        $container = ContainerScope::getContainer() ?? $this->container;
        if (!$container instanceof BinderInterface) {
            return $handler->handle($context);
        }

        $container->bindSingleton(IdempotencyContext::class, $operation);
        try {
            return $handler->handle($context);
        } finally {
            $container->removeBinding(IdempotencyContext::class);
        }
    }

    private function attribute(?\ReflectionFunctionAbstract $reflection): ?Idempotent
    {
        if ($reflection === null) {
            return null;
        }

        foreach ($reflection->getAttributes(Idempotent::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $instance = $attribute->newInstance();
            \assert($instance instanceof Idempotent);

            return $instance;
        }

        return null;
    }

    private function material(Idempotent $attribute, CallContextInterface $context): ?string
    {
        $material = $attribute->key === null
            ? null
            : $this->dotGet($context->getArguments(), $attribute->key);

        // No explicit key in the arguments → let the transport's source extract it from the context.
        return $material ?? $this->scoped(KeySourceInterface::class)->extract($context);
    }

    /**
     * @param array<array-key, mixed> $data
     * @param string $path dot-notation path
     */
    private function dotGet(array $data, string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $cursor = $data;
        foreach (\explode('.', $path) as $segment) {
            if (\is_array($cursor) && \array_key_exists($segment, $cursor)) {
                $cursor = $cursor[$segment];
                continue;
            }
            if (\is_object($cursor) && isset($cursor->{$segment})) {
                $cursor = $cursor->{$segment};
                continue;
            }
            return null;
        }

        return \is_scalar($cursor) ? (string) $cursor : null;
    }

    /**
     * Resolve a transport-specific service from the active scope (falling back to the root container),
     * so the right per-scope binding is used at call time without a proxy.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function scoped(string $id): object
    {
        $container = ContainerScope::getContainer() ?? $this->container;
        $service = $container->get($id);
        \assert($service instanceof $id);

        return $service;
    }
}
