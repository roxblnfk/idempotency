<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Bootloader;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Spiral\Core\Container;
use Spiral\Core\Scope;
use Spiral\Idempotency\Bootloader\HttpIdempotencyBootloader;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Interceptor\IdempotencyInterceptor;
use Spiral\Idempotency\Internal\Key\KeyResolver;
use Spiral\Idempotency\KeyResolverInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

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

        (new HttpIdempotencyBootloader())->init($container);

        return $container;
    }

    public function resolvesHttpFlavoredInterceptorInsideHttpScope(): void
    {
        $container = $this->container();

        $interceptor = $container->runScope(
            new Scope(name: 'http'),
            static fn(IdempotencyInterceptor $interceptor): IdempotencyInterceptor => $interceptor,
        );

        Assert::instanceOf($interceptor, IdempotencyInterceptor::class);
    }

    public function interceptorIsASingletonOfTheHttpScope(): void
    {
        $container = $this->container();

        [$first, $second] = $container->runScope(
            new Scope(name: 'http'),
            static fn(ContainerInterface $scoped): array => [
                $scoped->get(IdempotencyInterceptor::class),
                $scoped->get(IdempotencyInterceptor::class),
            ],
        );

        Assert::same($first, $second);
    }

    public function interceptorIsNotResolvableFromTheRootScope(): never
    {
        $container = $this->container();

        // The class name is deliberately unbound in root: autowiring dies on the scalar $transport
        // argument instead of silently handing out a transport-flavored instance.
        Expect::exception(ContainerExceptionInterface::class);

        $container->get(IdempotencyInterceptor::class);
    }
}
