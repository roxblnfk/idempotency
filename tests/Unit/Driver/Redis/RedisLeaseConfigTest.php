<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Driver\Redis;

use Spiral\Idempotency\Config\StorageConfig;
use Spiral\Idempotency\Driver\Redis\Internal\RedisLeaseFactory;
use Spiral\Idempotency\Driver\Redis\RedisLeaseConfig;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\StorageServices;
use Spiral\Idempotency\Tests\Support\MutableClock;
use Spiral\Idempotency\Internal\Lease\RandomTokenFactory;
use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Serializer\Serializer\PhpSerializer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(RedisLeaseConfig::class)]
final class RedisLeaseConfigTest
{
    public function guaranteeDefaultsToAtLeastOnce(): void
    {
        Assert::same((new RedisLeaseConfig())->guarantee(), Guarantee::AtLeastOnce);
    }

    public function guaranteeReflectsTheConstructedValue(): void
    {
        Assert::same(
            (new RedisLeaseConfig(guarantee: Guarantee::ExactlyOnce))->guarantee(),
            Guarantee::ExactlyOnce,
        );
    }

    public function factoryNamesTheRedisLeaseFactory(): void
    {
        Assert::same((new RedisLeaseConfig())->factory(), RedisLeaseFactory::class);
    }

    public function factoryRejectsAForeignConfig(): void
    {
        // The guard runs before any I/O, so a lazily-connecting predis client (no server needed) is safe.
        $factory = new RedisLeaseFactory(new \Predis\Client());
        $foreign = new class extends StorageConfig {
            public function guarantee(): Guarantee
            {
                return Guarantee::AtLeastOnce;
            }

            public function factory(): string
            {
                return RedisLeaseFactory::class;
            }
        };

        Expect::exception(MisconfigurationException::class)->withMessageContaining('RedisLeaseConfig');

        $factory->create($foreign, new StorageServices(
            new MutableClock(),
            new RandomTokenFactory(),
            new DefaultFailureClassifier(),
            new PhpSerializer(),
        ));
    }
}
