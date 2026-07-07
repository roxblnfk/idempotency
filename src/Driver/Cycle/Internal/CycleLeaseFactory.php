<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle\Internal;

use Cycle\Database\DatabaseProviderInterface;
use Spiral\Idempotency\Config\StorageConfig;
use Spiral\Idempotency\Driver\Cycle\CycleLeaseConfig;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\IdempotencyInterface;
use Spiral\Idempotency\Internal\Lease\LeaseIdempotency;
use Spiral\Idempotency\Internal\Lease\LeaseManager;
use Spiral\Idempotency\Pipeline\Middleware\ClassifierMiddleware;
use Spiral\Idempotency\Pipeline\Pipeline;
use Spiral\Idempotency\StorageFactoryInterface;
use Spiral\Idempotency\StorageServices;

/**
 * Builds the lease engine ({@see LeaseManager} + {@see LeaseIdempotency}) over a
 * {@see CycleLeaseStorage} from a {@see CycleLeaseConfig}.
 *
 * @internal Resolved from {@see CycleLeaseConfig::factory()} via the container; not part of the public API.
 */
final class CycleLeaseFactory implements StorageFactoryInterface
{
    public function __construct(
        private readonly DatabaseProviderInterface $databases,
    ) {}

    public function create(StorageConfig $config, StorageServices $services): IdempotencyInterface
    {
        if (!$config instanceof CycleLeaseConfig) {
            throw new MisconfigurationException(\sprintf(
                '%s expects %s, got %s.',
                self::class,
                CycleLeaseConfig::class,
                $config::class,
            ));
        }

        $database = $this->databases->database($config->connection);

        /** @var Pipeline<\Spiral\Idempotency\Pipeline\ExecutionCall> $execution */
        $execution = new Pipeline(new ClassifierMiddleware($services->classifier));

        return new LeaseIdempotency(
            new LeaseManager(
                new CycleLeaseStorage($database, $services->clock, $config->table),
                $services->clock,
                $services->tokens,
            ),
            $execution,
            $config->lockTtl,
            $config->retentionTtl,
            $services->serializer,
            $services->classifier,
            logger: $services->logger,
        );
    }
}
