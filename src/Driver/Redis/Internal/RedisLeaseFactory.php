<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Redis\Internal;

use Psr\Container\ContainerInterface;
use Spiral\Idempotency\Config\StorageConfig;
use Spiral\Idempotency\Driver\Redis\PredisCommands;
use Spiral\Idempotency\Driver\Redis\RedisCommandsInterface;
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
 * The Redis connection comes from the container: a {@see RedisCommandsInterface} binding wins; without
 * one a `\Predis\ClientInterface` binding is wrapped in {@see PredisCommands}.
 *
 * @internal Resolved from {@see RedisLeaseConfig::factory()} via the container; not part of the public API.
 */
final readonly class RedisLeaseFactory implements StorageFactoryInterface
{
    public function __construct(
        private ContainerInterface $container,
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
                new RedisLeaseStorage($this->commands(), $services->clock, $config->keyPrefix),
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

    private function commands(): RedisCommandsInterface
    {
        if ($this->container->has(RedisCommandsInterface::class)) {
            /** @var RedisCommandsInterface */
            return $this->container->get(RedisCommandsInterface::class);
        }

        if (\interface_exists(\Predis\ClientInterface::class) && $this->container->has(\Predis\ClientInterface::class)) {
            /** @var \Predis\ClientInterface $client */
            $client = $this->container->get(\Predis\ClientInterface::class);

            return new PredisCommands($client);
        }

        throw new MisconfigurationException(
            'RedisLeaseConfig needs a Redis connection, but the container binds neither '
            . RedisCommandsInterface::class . ' nor \Predis\ClientInterface.',
            'Bind Spiral\Idempotency\Driver\Redis\RedisCommandsInterface to an adapter over the Redis client '
            . 'your application already uses, or install predis/predis and bind \Predis\ClientInterface.',
        );
    }
}
