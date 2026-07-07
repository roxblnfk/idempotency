<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Bootloader;

use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Core\FactoryInterface;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\StorageFactoryInterface;
use Spiral\Idempotency\StorageServices;
use Spiral\Idempotency\Internal\Key\KeyResolver;
use Spiral\Idempotency\Internal\Lease\RandomTokenFactory;
use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\Internal\SystemClock;
use Spiral\Idempotency\KeyResolverInterface;
use Spiral\Idempotency\Lease\TokenFactoryInterface;
use Spiral\Idempotency\Pipeline\FailureClassifierInterface;
use Spiral\Serializer\Serializer\PhpSerializer;
use Spiral\Serializer\SerializerInterface;

/**
 * Wires the library into a Spiral application: binds the public contracts to their internal
 * implementations and assembles the {@see IdempotencyRegistry} from {@see IdempotencyConfig}. Because
 * the bindings live here, every concrete class stays {@see \Spiral\Idempotency\Internal} (or a
 * driver's own Internal namespace) — consumers depend only on the interfaces.
 *
 * Requires the application to provide {@see DatabaseProviderInterface} (cycle/database bootloader).
 *
 * @api
 */
final class IdempotencyBootloader extends Bootloader
{
    public function defineSingletons(): array
    {
        return [
            ClockInterface::class => SystemClock::class,
            KeyResolverInterface::class => KeyResolver::class,
            TokenFactoryInterface::class => RandomTokenFactory::class,
            FailureClassifierInterface::class => DefaultFailureClassifier::class,
            IdempotencyRegistry::class => $this->initRegistry(...),
        ];
    }

    /**
     * Build the registry from config: each {@see \Spiral\Idempotency\Config\StorageConfig} DTO assembles
     * its own driver, and the registry verifies fail-fast that the driver can back the declared guarantee.
     *
     * Storage factories are resolved through the container, so a driver-specific factory injects its own
     * backend (a {@see \Cycle\Database\DatabaseProviderInterface}, a Redis client, ...) — keeping the core
     * free of any single backend.
     */
    public function initRegistry(
        IdempotencyConfig $config,
        ContainerInterface $container,
        FactoryInterface $factory,
        ClockInterface $clock,
        TokenFactoryInterface $tokens,
        FailureClassifierInterface $classifier,
    ): IdempotencyRegistry {
        $services = new StorageServices(
            $clock,
            $tokens,
            $classifier,
            self::serializer(),
            $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
        );

        $registry = new IdempotencyRegistry();
        foreach ($config->getStorages() as $alias => $storage) {
            $storageFactory = $factory->make($storage->factory());
            \assert($storageFactory instanceof StorageFactoryInterface);
            $registry->register($alias, $storageFactory->create($storage, $services), $storage->guarantee());
        }

        return $registry;
    }

    /**
     * The lease driver caches results via PHP serialization by default (handles arbitrary payloads).
     * Override this bootloader to plug a different {@see SerializerInterface} (json/proto/...).
     */
    private static function serializer(): SerializerInterface
    {
        return new PhpSerializer();
    }
}
