<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Interceptor;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\Idempotency\Attribute\Idempotent;
use Spiral\Idempotency\Guarantee;
use Spiral\Idempotency\Http\HttpKeySource;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Interceptor\IdempotencyInterceptor;
use Spiral\Idempotency\Http\HttpResultCodec;
use Spiral\Idempotency\Internal\Key\KeyResolver;
use Spiral\Idempotency\KeySourceInterface;
use Spiral\Idempotency\ResultCodecInterface;
use Spiral\Idempotency\Internal\Lease\LeaseIdempotency;
use Spiral\Idempotency\Internal\Lease\LeaseManager;
use Spiral\Idempotency\Internal\Lease\Storage\InMemoryLeaseStorage;
use Spiral\Idempotency\Tests\Support\MutableClock;
use Spiral\Interceptors\Context\CallContext;
use Spiral\Interceptors\Context\CallContextInterface;
use Spiral\Interceptors\Context\Target;
use Spiral\Interceptors\HandlerInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

final class AnnotatedFixture
{
    #[Idempotent(storage: 'http', key: 'key')]
    public function withKey(string $key): ResponseInterface
    {
        throw new \LogicException('Not invoked directly — the handler stub produces the response.');
    }

    #[Idempotent(storage: 'http')]
    public function fromHeader(): ResponseInterface
    {
        throw new \LogicException('Not invoked directly.');
    }

    public function plain(): ResponseInterface
    {
        throw new \LogicException('Not invoked directly.');
    }
}

/**
 * Handler stub: counts invocations and returns a fresh response whose body reveals the run number,
 * so a replayed (cached) response is detectable by its stale body.
 */
final class CountingHandler implements HandlerInterface
{
    public int $calls = 0;

    public function __construct(private readonly Psr17Factory $factory) {}

    public function handle(CallContextInterface $context): mixed
    {
        ++$this->calls;

        return $this->factory
            ->createResponse(200)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->factory->createStream('run#' . $this->calls));
    }
}

#[Test]
#[Covers(IdempotencyInterceptor::class)]
final class IdempotencyInterceptorTest
{
    private Psr17Factory $psr17;

    public function __construct()
    {
        $this->psr17 = new Psr17Factory();
    }

    private function interceptor(): IdempotencyInterceptor
    {
        $clock = new MutableClock();
        $registry = new IdempotencyRegistry();
        $registry->register(
            'http',
            new LeaseIdempotency(new LeaseManager(new InMemoryLeaseStorage($clock), $clock), lockTtl: 30, retentionTtl: 3600),
            Guarantee::AtLeastOnce,
        );

        $container = new class($this->psr17) implements ContainerInterface {
            public function __construct(private readonly Psr17Factory $psr17) {}

            public function get(string $id): object
            {
                return match ($id) {
                    KeySourceInterface::class => new HttpKeySource(),
                    ResultCodecInterface::class => new HttpResultCodec($this->psr17, $this->psr17),
                    default => throw new \LogicException("Unexpected service {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return \in_array($id, [KeySourceInterface::class, ResultCodecInterface::class], true);
            }
        };

        return new IdempotencyInterceptor($registry, new KeyResolver(), $container);
    }

    /**
     * @param non-empty-string $method
     * @param array<array-key, mixed> $arguments
     */
    private function context(string $method, array $arguments = [], ?ServerRequestInterface $request = null): CallContext
    {
        $target = Target::fromReflectionMethod(
            new \ReflectionMethod(AnnotatedFixture::class, $method),
            new AnnotatedFixture(),
        );

        return new CallContext(
            $target,
            $arguments,
            $request === null ? [] : [ServerRequestInterface::class => $request],
        );
    }

    public function cachesResponseAndReplaysWithoutRerunning(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        /** @var ResponseInterface $first */
        $first = $interceptor->intercept($this->context('withKey', ['key' => 'abc']), $handler);
        /** @var ResponseInterface $second */
        $second = $interceptor->intercept($this->context('withKey', ['key' => 'abc']), $handler);

        Assert::same($handler->calls, 1);
        Assert::same((string) $first->getBody(), 'run#1');
        Assert::same((string) $second->getBody(), 'run#1');
        Assert::same($first->getHeaderLine('Idempotency-Replay'), 'false');
        Assert::same($second->getHeaderLine('Idempotency-Replay'), 'true');
        Assert::same($second->getHeaderLine('Idempotency-Key'), 'abc');
    }

    public function differentKeysRunSeparately(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        $interceptor->intercept($this->context('withKey', ['key' => 'a']), $handler);
        $interceptor->intercept($this->context('withKey', ['key' => 'b']), $handler);

        Assert::same($handler->calls, 2);
    }

    public function resolvesKeyFromBodyWhenNotInRouteArgs(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        $request = $this->psr17->createServerRequest('POST', '/charge')->withParsedBody(['key' => 'from-body']);

        $first = $interceptor->intercept($this->context('withKey', [], $request), $handler);
        $second = $interceptor->intercept($this->context('withKey', [], $request), $handler);

        Assert::same($handler->calls, 1);
        Assert::same($first->getHeaderLine('Idempotency-Key'), 'from-body');
        Assert::same($second->getHeaderLine('Idempotency-Replay'), 'true');
    }

    public function resolvesKeyFromHeaderWhenAttributeKeyIsNull(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        $request = $this->psr17->createServerRequest('POST', '/charge')->withHeader('Idempotency-Key', 'hdr-1');

        $interceptor->intercept($this->context('fromHeader', [], $request), $handler);
        $interceptor->intercept($this->context('fromHeader', [], $request), $handler);

        Assert::same($handler->calls, 1);
    }

    public function passesThroughWhenNoAttribute(): void
    {
        $interceptor = $this->interceptor();
        $handler = new CountingHandler($this->psr17);

        $interceptor->intercept($this->context('plain'), $handler);
        $interceptor->intercept($this->context('plain'), $handler);

        // No attribute → no idempotency: the handler runs every time.
        Assert::same($handler->calls, 2);
    }
}
