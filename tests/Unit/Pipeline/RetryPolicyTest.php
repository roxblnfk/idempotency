<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Pipeline;

use Spiral\Idempotency\Pipeline\RetryableInterface;
use Spiral\Idempotency\Pipeline\RetryPolicy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RetryPolicy::class)]
final class RetryPolicyTest
{
    public function defaultsMirrorTemporal(): void
    {
        $policy = new RetryPolicy();

        Assert::same($policy->maximumAttempts, 0);
        Assert::same($policy->initialIntervalMs, 1000);
        Assert::same($policy->backoffCoefficient, 2.0);
        Assert::same($policy->maximumIntervalMs, 100_000);
    }

    public function delayGrowsExponentially(): void
    {
        $policy = new RetryPolicy(initialIntervalMs: 1000, backoffCoefficient: 2.0, maximumIntervalMs: 100_000);

        Assert::same($policy->delayFor(1), 1000);
        Assert::same($policy->delayFor(2), 2000);
        Assert::same($policy->delayFor(3), 4000);
    }

    public function delayIsCappedAtMaximum(): void
    {
        $policy = new RetryPolicy(initialIntervalMs: 1000, backoffCoefficient: 10.0, maximumIntervalMs: 5000);

        Assert::same($policy->delayFor(5), 5000);
    }

    public function nonRetryableExceptionsAreNotRetryable(): void
    {
        $policy = new RetryPolicy(nonRetryableExceptions: [\LogicException::class]);

        Assert::false($policy->isRetryable(new \LogicException('x')));
        Assert::true($policy->isRetryable(new \RuntimeException('x')));
    }

    public function unrelatedRetryableMarkerStillRetryable(): void
    {
        $e = new class ('x') extends \Exception implements RetryableInterface {};

        Assert::true((new RetryPolicy())->isRetryable($e));
    }
}
