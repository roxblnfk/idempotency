<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Bootloader;

use Psr\Clock\ClockInterface;
use Spiral\Core\Container;
use Spiral\Idempotency\Bootloader\IdempotencyBootloader;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Internal\Key\KeyResolver;
use Spiral\Idempotency\Internal\Lease\RandomTokenFactory;
use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\Internal\SystemClock;
use Spiral\Idempotency\KeyResolverInterface;
use Spiral\Idempotency\Lease\TokenFactoryInterface;
use Spiral\Idempotency\Pipeline\FailureClassifierInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(IdempotencyBootloader::class)]
final class IdempotencyBootloaderTest
{
    /**
     * A container wired from the bootloader's own singleton definitions, plus the config the
     * registry closure reads. An application container would provide the same.
     */
    private function container(?IdempotencyConfig $config = null): Container
    {
        $container = new Container();
        $container->bindSingleton(
            IdempotencyConfig::class,
            $config ?? new IdempotencyConfig(['storages' => []]),
        );

        $bootloader = new IdempotencyBootloader();
        foreach ($bootloader->defineSingletons() as $alias => $resolver) {
            $container->bindSingleton($alias, $resolver);
        }

        return $container;
    }

    public function bindsClockInterfaceToSystemClock(): void
    {
        $clock = $this->container()->get(ClockInterface::class);

        Assert::instanceOf($clock, SystemClock::class);
    }

    public function bindsKeyResolverInterface(): void
    {
        $resolver = $this->container()->get(KeyResolverInterface::class);

        Assert::instanceOf($resolver, KeyResolver::class);
    }

    public function bindsTokenFactoryInterface(): void
    {
        $tokens = $this->container()->get(TokenFactoryInterface::class);

        Assert::instanceOf($tokens, RandomTokenFactory::class);
    }

    public function bindsFailureClassifierInterface(): void
    {
        $classifier = $this->container()->get(FailureClassifierInterface::class);

        Assert::instanceOf($classifier, DefaultFailureClassifier::class);
    }

    /**
     * Resolving IdempotencyRegistry invokes the initRegistry closure the bootloader binds: with an
     * empty storage config it builds an empty registry (no driver, no database), proving the wiring
     * runs end-to-end without a booted backend.
     */
    public function buildsRegistryFromConfigViaClosure(): void
    {
        $registry = $this->container()->get(IdempotencyRegistry::class);

        Assert::instanceOf($registry, IdempotencyRegistry::class);
    }

    /**
     * The registry is a singleton: the closure runs once and the same instance is returned.
     */
    public function registryIsASingleton(): void
    {
        $container = $this->container();

        Assert::same(
            $container->get(IdempotencyRegistry::class),
            $container->get(IdempotencyRegistry::class),
        );
    }
}
