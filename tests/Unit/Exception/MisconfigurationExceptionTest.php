<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Exception;

use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\Exception\IdempotencyException;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\FriendlyException\FriendlyExceptionInterface;

#[Test]
#[Covers(MisconfigurationException::class)]
final class MisconfigurationExceptionTest
{
    public function isFriendlyAndCarriesTheSolution(): void
    {
        $e = new MisconfigurationException('broken', 'fix it like this');

        Assert::instanceOf($e, FriendlyExceptionInterface::class);
        Assert::instanceOf($e, IdempotencyException::class);
        Assert::same($e->getName(), 'Idempotency misconfiguration');
        Assert::same($e->getSolution(), 'fix it like this');
    }

    public function solutionIsOptional(): void
    {
        Assert::null((new MisconfigurationException('broken'))->getSolution());
    }

    public function unknownTransportCarriesAnActionableSolution(): void
    {
        try {
            (new IdempotencyConfig(['transports' => []]))->getTransport('http');
            Assert::fail('An unconfigured transport must throw.');
        } catch (MisconfigurationException $e) {
            Assert::string((string) $e->getSolution())->contains('config/idempotency.php');
        }
    }
}
