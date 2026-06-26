<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit;

use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\GuaranteeProviderInterface;
use Spiral\Idempotency\IdempotencyInterface;
use Spiral\Idempotency\IdempotencyRegistry;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(IdempotencyRegistry::class)]
#[Covers(Guarantee::class)]
final class IdempotencyRegistryTest
{
    public function resolvesRegisteredAlias(): void
    {
        $driver = $this->driver(Guarantee::ExactlyOnce);
        $registry = new IdempotencyRegistry();

        $registry->register('orders', $driver, Guarantee::ExactlyOnce);

        Assert::same($registry->get('orders'), $driver);
        Assert::true($registry->has('orders'));
    }

    public function atLeastOnceDriverSatisfiesAtLeastOnceAlias(): void
    {
        $registry = new IdempotencyRegistry();

        $registry->register('notifications', $this->driver(Guarantee::AtLeastOnce), Guarantee::AtLeastOnce);

        Assert::true($registry->has('notifications'));
    }

    public function exactlyOnceDriverMaySatisfyAtLeastOnceAlias(): void
    {
        $registry = new IdempotencyRegistry();

        $registry->register('mixed', $this->driver(Guarantee::ExactlyOnce), Guarantee::AtLeastOnce);

        Assert::true($registry->has('mixed'));
    }

    public function atLeastOnceDriverCannotBackExactlyOnceAlias(): never
    {
        $registry = new IdempotencyRegistry();

        Expect::exception(MisconfigurationException::class)->withMessageContaining('ExactlyOnce');

        $registry->register('orders', $this->driver(Guarantee::AtLeastOnce), Guarantee::ExactlyOnce);
    }

    public function unknownAliasThrows(): never
    {
        Expect::exception(MisconfigurationException::class);

        (new IdempotencyRegistry())->get('missing');
    }

    private function driver(Guarantee $guarantee): IdempotencyInterface
    {
        return new class ($guarantee) implements IdempotencyInterface, GuaranteeProviderInterface {
            public function __construct(private readonly Guarantee $guarantee) {}

            public function execute(string $key, \Closure $operation, ?ExecuteOptions $options = null): mixed
            {
                return $operation;
            }

            public function guarantee(): Guarantee
            {
                return $this->guarantee;
            }
        };
    }
}
