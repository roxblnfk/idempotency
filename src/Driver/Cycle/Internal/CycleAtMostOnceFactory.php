<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle\Internal;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseProviderInterface;
use Psr\Container\ContainerInterface;
use Spiral\Idempotency\Config\StorageConfig;
use Spiral\Idempotency\Driver\Cycle\CycleAtMostOnceConfig;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\IdempotencyInterface;
use Spiral\Idempotency\StorageFactoryInterface;
use Spiral\Idempotency\StorageServices;

/**
 * Builds a {@see CycleAtMostOnceDriver} over a plain {@see DatabaseInterface} from a
 * {@see CycleAtMostOnceConfig}.
 *
 * Unlike the inbox, this driver needs no {@see \Cycle\Transaction\Transaction} — its marker commits in
 * autocommit — so only the DBAL {@see DatabaseProviderInterface} is required, resolved LAZILY (a closure
 * handed to the driver, not a ready database) to avoid coupling registry assembly to ORM schema
 * compilation, which itself runs this package's
 * {@see \Spiral\Idempotency\Driver\Cycle\Internal\Schema\IdempotencyTablesGenerator}.
 *
 * @internal Resolved from {@see CycleAtMostOnceConfig::factory()} via the container; not part of the public API.
 */
final class CycleAtMostOnceFactory implements StorageFactoryInterface
{
    public function __construct(
        private readonly ContainerInterface $container,
    ) {}

    public function create(StorageConfig $config, StorageServices $services): IdempotencyInterface
    {
        if (!$config instanceof CycleAtMostOnceConfig) {
            throw new MisconfigurationException(
                \sprintf('%s expects %s, got %s.', self::class, CycleAtMostOnceConfig::class, $config::class),
                'A storage config and its factory() must pair up: CycleAtMostOnceConfig → '
                . 'CycleAtMostOnceFactory. Check the factory() method of the config class.',
            );
        }

        return new CycleAtMostOnceDriver(
            fn(): DatabaseInterface => $this->database($config->connection),
            $services->clock,
            $config->connection,
            $config->table,
            // The serializer is only ever touched when the result cache is on.
            $config->cacheResult ? $services->serializer : null,
            $config->cacheResult,
        );
    }

    /**
     * Lazily resolve the DBAL database at execute() time, by which point the schema is already built.
     */
    private function database(?string $connection): DatabaseInterface
    {
        $databases = $this->container->get(DatabaseProviderInterface::class);
        \assert($databases instanceof DatabaseProviderInterface);

        return $databases->database($connection ?: null);
    }
}
