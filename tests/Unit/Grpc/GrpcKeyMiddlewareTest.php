<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Grpc;

use Spiral\Idempotency\Exception\MissingKeyException;
use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\Grpc\GrpcKeyMiddleware;
use Spiral\Idempotency\Internal\Key\DefaultKeyResolver;
use Spiral\Idempotency\Pipeline\IdempotencyCall;
use Spiral\Interceptors\Context\CallContext;
use Spiral\Interceptors\Context\Target;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

final class GrpcServiceFixture
{
    public function handle(): void
    {
        throw new \LogicException('Not invoked directly.');
    }
}

final class FakeGrpcContext implements ContextInterface
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        private array $values = [],
    ) {}

    public function withValue(string $key, mixed $value): static
    {
        $clone = clone $this;
        $clone->values[$key] = $value;
        return $clone;
    }

    public function getValue(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function getValues(): array
    {
        return $this->values;
    }
}

#[Test]
#[Covers(GrpcKeyMiddleware::class)]
final class GrpcKeyMiddlewareTest
{
    /**
     * @param array<string, mixed> $metadata
     */
    private function grpcContext(array $metadata = []): CallContext
    {
        $target = Target::fromReflectionMethod(
            new \ReflectionMethod(GrpcServiceFixture::class, 'handle'),
            new GrpcServiceFixture(),
        );

        // The gRPC context (metadata carrier) travels as the first positional call argument; the decoded
        // request message would be the second (a dummy stdClass here, unused by this middleware).
        return new CallContext($target, [new FakeGrpcContext($metadata), new \stdClass()]);
    }

    private function call(mixed $context, ?string $key = null, ?string $keyScope = null): IdempotencyCall
    {
        return new IdempotencyCall(
            context: $context,
            operation: static fn(): mixed => null,
            options: new ExecuteOptions(),
            key: $key,
            keyScope: $keyScope,
        );
    }

    public function passesThroughWhenKeyAlreadyResolved(): void
    {
        $middleware = new GrpcKeyMiddleware(new DefaultKeyResolver());
        $call = $this->call($this->grpcContext(['idempotency-key' => ['abc-1']]), key: 'existing');

        $received = null;
        $middleware->process($call, static function (IdempotencyCall $call) use (&$received): mixed {
            $received = $call;
            return null;
        });

        Assert::notNull($received);
        Assert::same($received->key, 'existing');
    }

    public function passesThroughWhenContextNotGrpc(): void
    {
        $middleware = new GrpcKeyMiddleware(new DefaultKeyResolver());
        $call = $this->call('not-a-context');

        $received = null;
        $middleware->process($call, static function (IdempotencyCall $call) use (&$received): mixed {
            $received = $call;
            return null;
        });

        Assert::notNull($received);
        Assert::same($received->key, null);
    }

    public function extractsKeyFromMetadata(): void
    {
        $middleware = new GrpcKeyMiddleware(new DefaultKeyResolver());
        $call = $this->call(
            $this->grpcContext(['idempotency-key' => ['abc-1']]),
            keyScope: 'Op::run',
        );

        $received = null;
        $middleware->process($call, static function (IdempotencyCall $call) use (&$received): mixed {
            $received = $call;
            return null;
        });

        Assert::notNull($received);
        Assert::same($received->key, (new DefaultKeyResolver())->resolve('abc-1', 'Op::run'));
    }

    public function matchesMetadataKeyCaseInsensitively(): void
    {
        $middleware = new GrpcKeyMiddleware(new DefaultKeyResolver());
        $call = $this->call($this->grpcContext(['Idempotency-Key' => ['abc-2']]));

        $received = null;
        $middleware->process($call, static function (IdempotencyCall $call) use (&$received): mixed {
            $received = $call;
            return null;
        });

        Assert::notNull($received);
        Assert::same($received->key, (new DefaultKeyResolver())->resolve('abc-2', null));
    }

    public function customMetadataKey(): void
    {
        $middleware = new GrpcKeyMiddleware(new DefaultKeyResolver(), 'x-dedup');
        $call = $this->call($this->grpcContext(['x-dedup' => ['zzz']]));

        $received = null;
        $middleware->process($call, static function (IdempotencyCall $call) use (&$received): mixed {
            $received = $call;
            return null;
        });

        Assert::notNull($received);
        Assert::same($received->key, (new DefaultKeyResolver())->resolve('zzz', null));
    }

    public function missingMetadataThrowsMissingKey(): void
    {
        $middleware = new GrpcKeyMiddleware(new DefaultKeyResolver());
        $call = $this->call($this->grpcContext([]));

        Expect::exception(MissingKeyException::class);

        $middleware->process($call, static fn(IdempotencyCall $call): mixed => null);
    }

    public function blankMetadataThrowsMissingKey(): void
    {
        $middleware = new GrpcKeyMiddleware(new DefaultKeyResolver());
        $call = $this->call($this->grpcContext(['idempotency-key' => ['']]));

        Expect::exception(MissingKeyException::class);

        $middleware->process($call, static fn(IdempotencyCall $call): mixed => null);
    }
}
