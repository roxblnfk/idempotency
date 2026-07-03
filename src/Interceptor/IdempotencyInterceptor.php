<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Interceptor;

use Psr\Container\ContainerInterface;
use Spiral\Core\BinderInterface;
use Spiral\Core\ContainerScope;
use Spiral\Idempotency\Attribute\Idempotent;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\Exception\NonDeterministicKeyException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\KeyResolverInterface;
use Spiral\Idempotency\Pipeline\IdempotencyCall;
use Spiral\Idempotency\Pipeline\Pipeline;
use Spiral\Idempotency\Pipeline\ResolutionMiddleware;
use Spiral\Interceptors\Context\CallContextInterface;
use Spiral\Interceptors\HandlerInterface;
use Spiral\Interceptors\InterceptorInterface;

/**
 * Makes controller/handler actions idempotent declaratively via {@see Idempotent}.
 *
 * Thin, transport-agnostic entry point into the config-driven pipeline: it reads the attribute, builds
 * an {@see IdempotencyCall} (the whole call context as {@see IdempotencyCall::$context}), assembles this
 * transport's resolution stack from config, and runs it around the storage handler
 * (`registry->get(storage)->execute(...)`).
 *
 * Everything transport-specific lives in the resolution middleware (HTTP key extraction, response
 * marshalling, Locked→409). One instance per transport, parameterized by {@see $transport} — the same
 * class serves HTTP, Queue, Events by pointing at a different config stack.
 *
 * The attribute's `key` arg-path (dot-notation over the call arguments) is resolved here, since it is
 * transport-independent and needs the attribute; a null result lets a transport middleware supply the
 * key instead.
 *
 * @api
 */
final class IdempotencyInterceptor implements InterceptorInterface
{
    /**
     * @param non-empty-string $transport config key of this transport's resolution stack
     */
    public function __construct(
        private readonly IdempotencyRegistry $registry,
        private readonly KeyResolverInterface $keys,
        private readonly ContainerInterface $container,
        private readonly IdempotencyConfig $config,
        private readonly string $transport,
    ) {}

    public function intercept(CallContextInterface $context, HandlerInterface $handler): mixed
    {
        $attribute = $this->attribute($context->getTarget()->getReflection());

        if ($attribute === null) {
            return $handler->handle($context);
        }

        $storage = $attribute->storage;
        $call = new IdempotencyCall(
            context: $context,
            // Expose the driver's context (e.g. a CycleContext with the transactional connection) so the
            // action can inject IdempotencyContext and narrow to it.
            operation: fn(IdempotencyContext $operation): mixed => $this->dispatch($operation, $handler, $context),
            options: new ExecuteOptions($attribute->lockTtl, $attribute->ttl),
            key: $this->keyFromArguments($attribute, $context),
        );

        return $this->pipeline()->process(
            $call,
            fn(IdempotencyCall $c): mixed => $this->registry->get($storage)->execute(
                $c->key ?? throw new NonDeterministicKeyException(
                    'No idempotency key could be resolved for the request.',
                ),
                $c->operation,
                $c->options,
            ),
        );
    }

    /**
     * Assemble this transport's resolution stack from config, resolving each middleware via the container.
     *
     * @return Pipeline<IdempotencyCall>
     */
    private function pipeline(): Pipeline
    {
        $middleware = [];
        foreach ($this->config->getTransport($this->transport) as $class) {
            $instance = $this->container->get($class);
            \assert($instance instanceof ResolutionMiddleware);
            $middleware[] = $instance;
        }

        /** @var Pipeline<IdempotencyCall> */
        return new Pipeline(...$middleware);
    }

    /**
     * Resolve the key from the attribute's arg-path (dot-notation over the call arguments), or null when
     * absent — letting a transport middleware extract it instead.
     *
     * @return non-empty-string|null
     */
    private function keyFromArguments(Idempotent $attribute, CallContextInterface $context): ?string
    {
        if ($attribute->key === null) {
            return null;
        }

        $raw = $this->dotGet($context->getArguments(), $attribute->key);

        return $raw === null ? null : $this->keys->resolve($raw);
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
}
