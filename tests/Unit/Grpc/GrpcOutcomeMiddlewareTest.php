<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Grpc;

use Spiral\Idempotency\Exception\LockedException;
use Spiral\Idempotency\Exception\MissingKeyException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\Grpc\GrpcOutcomeMiddleware;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\Lease\Locked;
use Spiral\Idempotency\Pipeline\IdempotencyCall;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\StatusCode;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

final class FakeGrpcMessage
{
    public function __construct(public string $payload = '') {}

    public function serializeToString(): string
    {
        return $this->payload;
    }

    public function mergeFromString(string $data): void
    {
        $this->payload = $data;
    }
}

#[Test]
#[Covers(GrpcOutcomeMiddleware::class)]
final class GrpcOutcomeMiddlewareTest
{
    private function context(): IdempotencyContext
    {
        return new class implements IdempotencyContext {
            public function getKey(): string
            {
                return 'k';
            }

            public function renew(bool $force = false): void
            {
            }
        };
    }

    private function call(\Closure $operation): IdempotencyCall
    {
        return new IdempotencyCall(
            context: new \stdClass(),
            operation: $operation,
            options: new ExecuteOptions(),
        );
    }

    public function snapshotsAndRebuildsMessageOnFreshCall(): void
    {
        $middleware = new GrpcOutcomeMiddleware();
        $ctx = $this->context();
        $call = $this->call(static fn(): mixed => new FakeGrpcMessage('hello'));

        // The `$next` executes the encoded operation, mimicking the driver handler that caches whatever
        // the operation returns (here: the [class, bytes] snapshot produced by encode()).
        $next = static fn(IdempotencyCall $c): mixed => ($c->operation)($ctx);

        $result = $middleware->process($call, $next);

        Assert::true($result instanceof FakeGrpcMessage);
        /** @var FakeGrpcMessage $result */
        Assert::same($result->payload, 'hello');
    }

    public function rebuildsMessageFromCachedSnapshot(): void
    {
        $middleware = new GrpcOutcomeMiddleware();
        $call = $this->call(static function (): never {
            throw new \LogicException('The operation must not run on a replay.');
        });

        // Simulate a replay: `$next` returns a previously cached snapshot without invoking the operation.
        $next = static fn(IdempotencyCall $c): mixed => [
            '__idempotency_grpc_message__' => true,
            'class' => FakeGrpcMessage::class,
            'bytes' => 'world',
        ];

        $result = $middleware->process($call, $next);

        Assert::true($result instanceof FakeGrpcMessage);
        /** @var FakeGrpcMessage $result */
        Assert::same($result->payload, 'world');
    }

    public function passesThroughNonMessageResult(): void
    {
        $middleware = new GrpcOutcomeMiddleware();
        $ctx = $this->context();
        $call = $this->call(static fn(): mixed => 'plain');

        $next = static fn(IdempotencyCall $c): mixed => ($c->operation)($ctx);

        $result = $middleware->process($call, $next);

        Assert::same($result, 'plain');
    }

    public function mapsLockedToAborted(): void
    {
        $middleware = new GrpcOutcomeMiddleware();
        $call = $this->call(static fn(): mixed => null);
        $locked = new LockedException(new Locked('k', 7, new \DateTimeImmutable()));

        $next = static function (IdempotencyCall $c) use ($locked): never {
            throw $locked;
        };

        $thrown = null;
        try {
            $middleware->process($call, $next);
            Assert::fail('a LockedException must be mapped to a GRPCException');
        } catch (GRPCException $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        Assert::same($thrown->getCode(), StatusCode::ABORTED);
        Assert::same($thrown->getPrevious(), $locked);
    }

    public function mapsMissingKeyToInvalidArgument(): void
    {
        $middleware = new GrpcOutcomeMiddleware();
        $call = $this->call(static fn(): mixed => null);

        $next = static function (IdempotencyCall $c): never {
            throw new MissingKeyException('no key');
        };

        $thrown = null;
        try {
            $middleware->process($call, $next);
            Assert::fail('a MissingKeyException must be mapped to a GRPCException');
        } catch (GRPCException $e) {
            $thrown = $e;
        }

        Assert::notNull($thrown);
        Assert::same($thrown->getCode(), StatusCode::INVALID_ARGUMENT);
    }
}
