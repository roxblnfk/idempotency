<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Pipeline\Middleware;

use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\Exception\ClassifiedException;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\Pipeline\ExecutionCall;
use Spiral\Idempotency\Pipeline\FailureKind;
use Spiral\Idempotency\Pipeline\Middleware\ClassifierMiddleware;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ClassifierMiddleware::class)]
final class ClassifierMiddlewareTest
{
    public function passesSuccessThrough(): void
    {
        $middleware = new ClassifierMiddleware(new DefaultFailureClassifier());

        $result = $middleware->process($this->call(), static fn(ExecutionCall $c): string => 'ok');

        Assert::same($result, 'ok');
    }

    public function classifiesPlainExceptionAsDomain(): void
    {
        $middleware = new ClassifierMiddleware(new DefaultFailureClassifier());

        try {
            $middleware->process($this->call(), static fn(): mixed => throw new \RuntimeException('no funds'));
            Assert::fail('should throw ClassifiedException');
        } catch (ClassifiedException $e) {
            Assert::same($e->kind, FailureKind::Domain);
            Assert::same($e->getPrevious()?->getMessage(), 'no funds');
        }
    }

    public function classifiesErrorAsInfrastructure(): void
    {
        $middleware = new ClassifierMiddleware(new DefaultFailureClassifier());

        try {
            $middleware->process($this->call(), static fn(): mixed => throw new \Error('transient'));
            Assert::fail('should throw ClassifiedException');
        } catch (ClassifiedException $e) {
            Assert::same($e->kind, FailureKind::Infrastructure);
        }
    }

    public function doesNotReclassifyAnAlreadyClassifiedException(): void
    {
        $middleware = new ClassifierMiddleware(new DefaultFailureClassifier());
        $preset = new ClassifiedException(FailureKind::Bug, new \LogicException('marked'));

        try {
            $middleware->process($this->call(), static fn(): mixed => throw $preset);
            Assert::fail('should rethrow');
        } catch (ClassifiedException $e) {
            Assert::same($e, $preset);           // same instance, not re-wrapped
            Assert::same($e->kind, FailureKind::Bug);
        }
    }

    private function call(): ExecutionCall
    {
        return new ExecutionCall(
            context: new class implements IdempotencyContext {
                public function getKey(): string
                {
                    return 'k';
                }
            },
            operation: static fn(): null => null,
            options: new ExecuteOptions(),
        );
    }
}
