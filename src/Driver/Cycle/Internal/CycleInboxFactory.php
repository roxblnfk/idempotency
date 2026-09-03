<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle\Internal;

use Cycle\Database\DatabaseProviderInterface;
use Cycle\ORM\ORMInterface;
use Cycle\Transaction\Internal\TransactionImpl;
use Cycle\Transaction\Transaction;
use Psr\Container\ContainerInterface;
use Spiral\Idempotency\Config\StorageConfig;
use Spiral\Idempotency\Driver\Cycle\CycleInboxConfig;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\Idempotency;
use Spiral\Idempotency\StorageFactory;
use Spiral\Idempotency\StorageServices;

/**
 * Builds a {@see CycleInboxDriver} over a {@see Transaction} from a {@see CycleInboxConfig}.
 *
 * The {@see Transaction} (and thus {@see ORMInterface}) is resolved LAZILY — the driver receives a
 * factory closure, not a ready transaction. Resolving the ORM eagerly here would couple registry
 * assembly to ORM schema compilation (which itself runs this package's
 * {@see \Spiral\Idempotency\Driver\Cycle\Internal\Schema\IdempotencyTablesGenerator}), creating a
 * container dependency cycle when the domain interceptor pipeline is built.
 *
 * @internal Resolved from {@see CycleInboxConfig::factory()} via the container; not part of the public API.
 */
final readonly class CycleInboxFactory implements StorageFactory
{
    public function __construct(
        private ContainerInterface $container,
    ) {}

    public function create(StorageConfig $config, StorageServices $services): Idempotency
    {
        if (!$config instanceof CycleInboxConfig) {
            throw new MisconfigurationException(
                \sprintf('%s expects %s, got %s.', self::class, CycleInboxConfig::class, $config::class),
                'A storage config and its factory() must pair up: CycleInboxConfig → CycleInboxFactory, '
                . 'CycleLeaseConfig → CycleLeaseFactory. Check the factory() method of the config class.',
            );
        }

        return new CycleInboxDriver(
            $this->transaction(...),
            $services->clock,
            $config->connection,
            $config->table,
            $services->serializer,
            $config->transactionMode,
            $config->flushMode,
        );
    }

    /**
     * Lazily assemble the transaction at execute() time, by which point the ORM is already built.
     */
    private function transaction(): Transaction
    {
        $orm = $this->container->get(ORMInterface::class);
        \assert($orm instanceof ORMInterface);

        $databases = $this->container->get(DatabaseProviderInterface::class);
        \assert($databases instanceof DatabaseProviderInterface);

        return new TransactionImpl($orm, $databases);
    }
}
