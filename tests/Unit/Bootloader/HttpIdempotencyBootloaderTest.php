<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Bootloader;

use Psr\Container\ContainerInterface;
use Spiral\Core\Container;
use Spiral\Core\Scope;
use Spiral\Idempotency\Bootloader\HttpIdempotencyBootloader;
use Spiral\Idempotency\Bootloader\IdempotencyBootloader;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Interceptor\IdempotencyInterceptor;
use Spiral\Idempotency\Interceptor\IdempotencyInterceptorInterface;
use Spiral\Idempotency\Internal\Key\KeyResolver;
use Spiral\Idempotency\KeyResolverInterface;
use Spiral\Interceptors\Context\CallContext;
use Spiral\Interceptors\Context\CallContextInterface;
use Spiral\Interceptors\HandlerInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

final class PlainFixture
{
    public function plain(): string
    {
        throw new \LogicException('Not invoked directly — the handler stub produces the result.');
    }
}

#[Test]
#[Covers(HttpIdempotencyBootloader::class)]
final class HttpIdempotencyBootloaderTest
{
    /**
     * The interceptor dependencies an application container would provide.
     */
    private function container(): Container
    {
        $container = new Container();
        $container->bindSingleton(IdempotencyRegistry::class, new IdempotencyRegistry());
        $container->bindSingleton(KeyResolverInterface::class, new KeyResolver());
        $container->bindSingleton(IdempotencyConfig::class, new IdempotencyConfig([
            'transports' => ['http' => []],
        ]));

        $bootloader = new HttpIdempotencyBootloader();
        foreach ($bootloader->defineBindings() as $alias => $resolver) {
            $container->bind($alias, $resolver);
        }
        $bootloader->init($container);

        return $container;
    }

    /**
     * A no-attribute call the interceptor passes straight through to the handler.
     */
    private function passthroughCall(): array
    {
        $context = new CallContext(
            \Spiral\Interceptors\Context\Target::fromReflectionMethod(
                new \ReflectionMethod(PlainFixture::class, 'plain'),
                new PlainFixture(),
            ),
        );

        $handler = new class implements HandlerInterface {
            public function handle(CallContextInterface $context): mixed
            {
                return 'handled';
            }
        };

        return [$context, $handler];
    }

    public function requiresCoreIdempotencyBootloader(): void
    {
        $bootloader = new HttpIdempotencyBootloader();

        Assert::same($bootloader->defineDependencies(), [IdempotencyBootloader::class]);
    }

    public function resolvesRealInterceptorInsideHttpScope(): void
    {
        $container = $this->container();

        $interceptor = $container->runScope(
            new Scope(name: 'http'),
            static fn(IdempotencyInterceptorInterface $interceptor): object => $interceptor,
        );

        Assert::instanceOf($interceptor, IdempotencyInterceptor::class);
    }

    public function interceptorIsASingletonOfTheHttpScope(): void
    {
        $container = $this->container();

        [$first, $second] = $container->runScope(
            new Scope(name: 'http'),
            static fn(ContainerInterface $scoped): array => [
                $scoped->get(IdempotencyInterceptorInterface::class),
                $scoped->get(IdempotencyInterceptorInterface::class),
            ],
        );

        Assert::same($first, $second);
    }

    public function rootYieldsProxyThatForwardsInsideHttpScope(): void
    {
        $container = $this->container();

        // Root resolution succeeds (a domain core built in root gets this proxy) ...
        $proxy = $container->get(IdempotencyInterceptorInterface::class);
        Assert::instanceOf($proxy, IdempotencyInterceptorInterface::class);
        // ... but it is not the real interceptor: it forwards per call.
        Assert::false($proxy instanceof IdempotencyInterceptor);

        [$context, $handler] = $this->passthroughCall();

        $result = $container->runScope(
            new Scope(name: 'http'),
            static fn(): mixed => $proxy->intercept($context, $handler),
        );

        Assert::same($result, 'handled');
    }

    public function proxyInvokedOutsideTransportScopeFailsFast(): never
    {
        $container = $this->container();

        $proxy = $container->get(IdempotencyInterceptorInterface::class);
        [$context, $handler] = $this->passthroughCall();

        Expect::exception(MisconfigurationException::class);

        // A scope with no transport binding on the chain: the proxy's fallback must fire.
        $container->runScope(
            new Scope(name: 'console'),
            static fn(): mixed => $proxy->intercept($context, $handler),
        );
    }
}
