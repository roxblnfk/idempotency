<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Pipeline;

use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\Pipeline\FailureKind;
use Spiral\Idempotency\Pipeline\Retryable;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(DefaultFailureClassifier::class)]
final class DefaultFailureClassifierTest
{
    public function errorIsInfrastructureByDefault(): void
    {
        Assert::same((new DefaultFailureClassifier())->classify(new \Error('x')), FailureKind::Infrastructure);
    }

    public function retryableExceptionIsInfrastructure(): void
    {
        $e = new class ('x') extends \Exception implements Retryable {};

        Assert::same((new DefaultFailureClassifier())->classify($e), FailureKind::Infrastructure);
    }

    public function plainExceptionIsDomain(): void
    {
        Assert::same((new DefaultFailureClassifier())->classify(new \RuntimeException('x')), FailureKind::Domain);
    }

    public function explicitlyMarkedExceptionIsBug(): void
    {
        $classifier = new DefaultFailureClassifier(bugExceptions: [\LogicException::class]);

        Assert::same($classifier->classify(new \LogicException('x')), FailureKind::Bug);
    }

    public function bugMarkingTakesPrecedenceOverErrorDefault(): void
    {
        $classifier = new DefaultFailureClassifier(bugExceptions: [\TypeError::class]);

        Assert::same($classifier->classify(new \TypeError('x')), FailureKind::Bug);
    }
}
