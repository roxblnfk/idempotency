<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Redis\Internal;

use Spiral\Idempotency\Config\StorageConfig;
use Spiral\Idempotency\Driver\Redis\RedisLeaseConfig;
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
 * {@see RedisLeaseStorage} from a {@see RedisLeaseConfig}.
 *
 * @internal Resolved from {@see RedisLeaseConfig::factory()} via the container; not part of the public API.
 */
final readonly class RedisLeaseFactory implements StorageFactoryInterface
{
    public function __construct(
        private \Predis\ClientInterface $client,
    ) {}

    public function create(StorageConfig $config, StorageServices $services): IdempotencyInterface
    {
        if (!$config instanceof RedisLeaseConfig) {
            throw new MisconfigurationException(
                \sprintf('%s expects %s, got %s.', self::class, RedisLeaseConfig::class, $config::class),
                'A storage config and its factory() must pair up: RedisLeaseConfig → RedisLeaseFactory, '
                . 'CycleLeaseConfig → CycleLeaseFactory. Check the factory() method of the config class.',
            );
        }

        /** @var Pipeline<\Spiral\Idempotency\Pipeline\ExecutionCall> $execution */
        $execution = new Pipeline(new ClassifierMiddleware($services->classifier));

        return new LeaseIdempotency(
            new LeaseManager(
                new RedisLeaseStorage($this->client, $services->clock, $config->keyPrefix),
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
