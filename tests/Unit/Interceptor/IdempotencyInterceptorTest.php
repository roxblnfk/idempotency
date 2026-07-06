<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Interceptor;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Spiral\Core\Container;
use Spiral\Core\ContainerScope;
use Spiral\Idempotency\Attribute\Idempotent;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\Http\HttpKeyMiddleware;
use Spiral\Idempotency\Http\HttpOutcomeMiddleware;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Interceptor\IdempotencyInterceptor;
use Spiral\Idempotency\Internal\Key\KeyResolver;
use Spiral\Idempotency\Internal\Lease\LeaseIdempotency;
use Spiral\Idempotency\Internal\Lease\LeaseManager;
use Spiral\Idempotency\Internal\Lease\Storage\InMemoryLeaseStorage;
use Spiral\Idempotency\KeyResolverInterface;
use Spiral\Idempotency\Pipeline\Pipeline;
use Spiral\Idempotency\Tests\Support\MutableClock;
use Spiral\Interceptors\Context\CallContext;
use Spiral\Interceptors\Context\CallContextInterface;
use Spiral\Interceptors\Context\Target;
use Spiral\Interceptors\HandlerInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

final class AnnotatedFixture
{
    #[Idempotent(storage: 'http', key: 'key')]
    public function withKey(string $key): ResponseInterface
    {
        throw new \LogicException('Not invoked directly — the handler stub produces the response.');
    }

    #[Idempotent(storage: 'http')]
    public function fromHeader(): ResponseInterface
    {
        throw new \LogicException('Not invoked directly.');
    }

    public function plain(): ResponseInterface
    {
        throw new \LogicException('Not invoked directly.');
    }
}

/**
 * Handler stub: counts invocations and returns a fresh response whose body reveals the run number,
 * so a replayed (cached) response is detectable by its stale body.
 */
final class CountingHandler implements HandlerInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly Psr17Factory $factory,
        private readonly int $status = 200,
    ) {}

    public function handle(CallContextInterface $context): mixed
    {
        ++$this->calls;

        return $this->factory
            ->createResponse($this->status)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->factory->createStream('run#' . $this->calls));
    }
}

#[Test]
#[Covers(IdempotencyInterceptor::class)]
final class IdempotencyInterceptorTest
{
    private Psr17Factory $psr17;

    public function __construct()
    {
        $this->psr17 = new Psr17Factory();
    }

    private function interceptor(): IdempotencyInterceptor
    {
        $clock = new MutableClock();
        $registry = new IdempotencyRegistry();
        $registry->register(
            'http',
            new LeaseIdempotency(
                new LeaseManager(new InMemoryLeaseStorage($clock), $clock),
                new Pipeline(),
                lockTtl: 30,
                retentionTtl: 3600,
            ),
            Guarantee::AtLeastOnce,
        );

        // Container resolves the HTTP resolution middleware named in the config stack.
        $container = new class($this->psr17) implements ContainerInterface {
            public function __construct(private readonly Psr17Factory $psr17) {}

            public function get(string $id): object
            {
                return match ($id) {
                    HttpKeyMiddleware::class => new HttpKeyMiddleware(new KeyResolver()),
                    HttpOutcomeMiddleware::class => new HttpOutcomeMiddleware($this->psr17, $this->psr17),
                    default => throw new \LogicException("Unexpected service {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return \in_array($id, [HttpKeyMiddleware::class, HttpOutcomeMiddleware::class], true);
            }
        };

        $config = new IdempotencyConfig([
            'default' => 'http',
            'storages' => [],
            'transports' => ['http' => [HttpKeyMiddleware::class, HttpOutcomeMiddleware::class]],
        ]);

        return new IdempotencyInterceptor($registry, new KeyResolver(), $container, $config, 'http');
    }

    /**
     * @param non-empty-string $method
     * @param array<array-key, mixed> $arguments
     */
    private function context(string $method, array $arguments = [], ?ServerRequestInterface $request = null): CallContext
    {
        $target = Target::fromReflectionMethod(
            new \ReflectionMethod(AnnotatedFixture::class, $method),
            new AnnotatedFixture(),
        );

        return new CallContext(
            $target,
            $arguments,
            $request === null ? [] : [ServerRequestInterface::class => $request],
        );
    }

    public function cachesResponseAndReplaysWithoutRerunning(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        /** @var ResponseInterface $first */
        $first = $interceptor->intercept($this->context('withKey', ['key' => 'abc']), $handler);
        /** @var ResponseInterface $second */
        $second = $interceptor->intercept($this->context('withKey', ['key' => 'abc']), $handler);

        Assert::same($handler->calls, 1);
        Assert::same((string) $first->getBody(), 'run#1');
        Assert::same((string) $second->getBody(), 'run#1');
        Assert::same($first->getHeaderLine('Idempotency-Replay'), 'false');
        Assert::same($second->getHeaderLine('Idempotency-Replay'), 'true');
        Assert::same($second->getHeaderLine('Idempotency-Key'), 'abc');
    }

    public function differentKeysRunSeparately(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        $interceptor->intercept($this->context('withKey', ['key' => 'a']), $handler);
        $interceptor->intercept($this->context('withKey', ['key' => 'b']), $handler);

        Assert::same($handler->calls, 2);
    }

    public function resolvesKeyFromBodyWhenNotInRouteArgs(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        $request = $this->psr17->createServerRequest('POST', '/charge')->withParsedBody(['key' => 'from-body']);

        /** @var ResponseInterface $first */
        $first = $interceptor->intercept($this->context('withKey', [], $request), $handler);
        /** @var ResponseInterface $second */
        $second = $interceptor->intercept($this->context('withKey', [], $request), $handler);

        Assert::same($handler->calls, 1);
        Assert::same($first->getHeaderLine('Idempotency-Key'), 'from-body');
        Assert::same($second->getHeaderLine('Idempotency-Replay'), 'true');
    }

    public function resolvesKeyFromHeaderWhenAttributeKeyIsNull(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        $request = $this->psr17->createServerRequest('POST', '/charge')->withHeader('Idempotency-Key', 'hdr-1');

        $interceptor->intercept($this->context('fromHeader', [], $request), $handler);
        $interceptor->intercept($this->context('fromHeader', [], $request), $handler);

        Assert::same($handler->calls, 1);
    }

    public function passesThroughWhenNoAttribute(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        $interceptor->intercept($this->context('plain'), $handler);
        $interceptor->intercept($this->context('plain'), $handler);

        // No attribute → no idempotency: the handler runs every time.
        Assert::same($handler->calls, 2);
    }

    public function bindsContextInIsolatedScopeWithoutLeaking(): void
    {
        // With a real Spiral container, dispatch() must expose IdempotencyContext to the action via an
        // isolated child scope — visible inside, but not leaked into the root container afterwards.
        $container = new Container();
        $container->bindSingleton(KeyResolverInterface::class, new KeyResolver());
        $container->bindSingleton(ResponseFactoryInterface::class, $this->psr17);
        $container->bindSingleton(StreamFactoryInterface::class, $this->psr17);

        $clock = new MutableClock();
        $registry = new IdempotencyRegistry();
        $registry->register(
            'http',
            new LeaseIdempotency(
                new LeaseManager(new InMemoryLeaseStorage($clock), $clock),
                new Pipeline(),
                lockTtl: 30,
                retentionTtl: 3600,
            ),
            Guarantee::AtLeastOnce,
        );
        $config = new IdempotencyConfig([
            'transports' => ['http' => [HttpKeyMiddleware::class, HttpOutcomeMiddleware::class]],
        ]);
        $interceptor = new IdempotencyInterceptor($registry, new KeyResolver(), $container, $config, 'http');

        $handler = new class($this->psr17) implements HandlerInterface {
            public bool $contextVisible = false;

            public function __construct(private readonly Psr17Factory $factory) {}

            public function handle(CallContextInterface $context): mixed
            {
                $scope = ContainerScope::getContainer();
                $this->contextVisible = $scope !== null && $scope->has(IdempotencyContext::class)
                    && $scope->get(IdempotencyContext::class) instanceof IdempotencyContext;

                return $this->factory->createResponse(200)->withBody($this->factory->createStream('ok'));
            }
        };

        $interceptor->intercept($this->context('withKey', ['key' => 'z']), $handler);

        Assert::true($handler->contextVisible);                    // injectable inside the scope
        Assert::false($container->has(IdempotencyContext::class));  // not leaked into the root container
    }

    public function transientResponseIsNotCachedAndReRuns(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17, status: 503);

        /** @var ResponseInterface $first */
        $first = $interceptor->intercept($this->context('withKey', ['key' => 'x']), $handler);
        /** @var ResponseInterface $second */
        $second = $interceptor->intercept($this->context('withKey', ['key' => 'x']), $handler);

        // 5xx is not cached (default policy): the key is released, so the second call re-runs.
        Assert::same($handler->calls, 2);
        Assert::same($first->getStatusCode(), 503);
        Assert::same($second->getStatusCode(), 503);
        Assert::same($second->getBody()->__toString(), 'run#2');
    }
}
