<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Driver\Redis;

use Spiral\Core\Container;
use Spiral\Idempotency\Driver\Redis\Internal\RedisLeaseFactory;
use Spiral\Idempotency\Driver\Redis\PredisCommands;
use Spiral\Idempotency\Driver\Redis\RedisCommands;
use Spiral\Idempotency\Driver\Redis\RedisLeaseConfig;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\Idempotency;
use Spiral\Idempotency\Internal\Lease\RandomTokenFactory;
use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\StorageServices;
use Spiral\Idempotency\Tests\Support\MutableClock;
use Spiral\Serializer\Serializer\PhpSerializer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

/**
 * How the factory finds its Redis connection. Nothing here talks to a server: predis connects lazily
 * and the failure path throws before any I/O.
 */
#[Test]
#[Covers(RedisLeaseFactory::class)]
final class RedisLeaseFactoryTest
{
    public function usesABoundCommandsAdapter(): void
    {
        $container = new Container();
        $container->bindSingleton(RedisCommands::class, $this->commands());

        $idempotency = new RedisLeaseFactory($container)->create(new RedisLeaseConfig(), $this->services());

        Assert::instanceOf($idempotency, Idempotency::class);
    }

    public function fallsBackToAPredisClientBinding(): void
    {
        $container = new Container();
        $container->bindSingleton(\Predis\ClientInterface::class, new \Predis\Client());

        $idempotency = new RedisLeaseFactory($container)->create(new RedisLeaseConfig(), $this->services());

        Assert::instanceOf($idempotency, Idempotency::class);
    }

    public function commandsBindingWinsOverPredis(): void
    {
        // A predis binding that explodes on resolution proves the adapter path is taken instead.
        $container = new Container();
        $container->bindSingleton(
            \Predis\ClientInterface::class,
            static fn(): never => throw new \LogicException('predis must not be resolved'),
        );
        $container->bindSingleton(RedisCommands::class, $this->commands());

        $idempotency = new RedisLeaseFactory($container)->create(new RedisLeaseConfig(), $this->services());

        Assert::instanceOf($idempotency, Idempotency::class);
    }

    public function failsFastWithoutAnyConnectionBinding(): void
    {
        Expect::exception(MisconfigurationException::class)->withMessageContaining('RedisCommands');

        new RedisLeaseFactory(new Container())->create(new RedisLeaseConfig(), $this->services());
    }

    private function commands(): RedisCommands
    {
        return new class implements RedisCommands {
            public function eval(string $script, array $keys, array $args): mixed
            {
                return 1;
            }

            public function hgetall(string $key): array
            {
                return [];
            }

            public function pttl(string $key): int
            {
                return -2;
            }
        };
    }

    private function services(): StorageServices
    {
        return new StorageServices(
            new MutableClock(),
            new RandomTokenFactory(),
            new DefaultFailureClassifier(),
            new PhpSerializer(),
        );
    }
}
