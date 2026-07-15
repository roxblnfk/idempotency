<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Queue;

use Spiral\Idempotency\Exception\LockedException;
use Spiral\Idempotency\Lease\Locked;
use Spiral\Idempotency\Queue\RetryableLockException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RetryableLockException::class)]
final class RetryableLockExceptionTest
{
    private function locked(int $retryAfter = 5): LockedException
    {
        return new LockedException(new Locked('k', $retryAfter, new \DateTimeImmutable()));
    }

    public function isAlwaysRetryable(): void
    {
        $exception = new RetryableLockException(5, 10, $this->locked());

        Assert::true($exception->isRetryable());
    }

    public function carriesConstantDelayFromRetryAfter(): void
    {
        $exception = new RetryableLockException(5, 10, $this->locked());
        $policy = $exception->getRetryPolicy();

        Assert::notNull($policy);
        Assert::same($policy->getDelay(0), 5);
        Assert::same($policy->getDelay(2), 5);
    }

    public function clampsZeroDelayToOne(): void
    {
        $exception = new RetryableLockException(0, 10, $this->locked());
        $policy = $exception->getRetryPolicy();

        Assert::notNull($policy);
        Assert::same($policy->getDelay(0), 1);
    }

    public function wrapsOriginalAsPrevious(): void
    {
        $locked = $this->locked();
        $exception = new RetryableLockException(5, 10, $locked);

        Assert::same($exception->getPrevious(), $locked);
        Assert::same($exception->getMessage(), $locked->getMessage());
    }
}
