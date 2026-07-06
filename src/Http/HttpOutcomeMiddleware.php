<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Spiral\Idempotency\Exception\LockedException;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\Lease\Locked;
use Spiral\Idempotency\Pipeline\IdempotencyCall;
use Spiral\Idempotency\Pipeline\ResolutionMiddleware;
use Spiral\Idempotency\Uncacheable;

/**
 * HTTP outcome middleware: maps the idempotent call to/from an HTTP response. Replaces the old
 * scope-bound `HttpResultCodec`, now living in the pipeline.
 *
 *  - wraps the operation so its {@see ResponseInterface} is snapshotted before the handler caches it
 *    (a PSR-7 response is not cleanly serializable);
 *  - rebuilds the response from the snapshot on the way out (via PSR-17 factories) and adds the
 *    `Idempotency-Key` / `Idempotency-Replay` headers;
 *  - turns a {@see LockedException} into `409 Conflict` + `Retry-After`;
 *  - wraps a non-cacheable response (default: status >= 500, transient) in {@see Uncacheable}, so the
 *    lease handler releases the key and a retry re-runs instead of replaying the error forever.
 *
 * Sits inside the key middleware (so {@see IdempotencyCall::$key} is already resolved for the replay
 * header). Non-response results pass through untouched.
 *
 * @api
 */
final readonly class HttpOutcomeMiddleware implements ResolutionMiddleware
{
    private const SNAPSHOT = '__idempotency_http_response__';

    /** @var \Closure(ResponseInterface): bool */
    private \Closure $cacheable;

    /**
     * @param (\Closure(ResponseInterface): bool)|null $cacheable decides whether a response may be
     *        cached; default: only status < 500 (transient 5xx are re-run, not replayed)
     */
    public function __construct(
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        ?\Closure $cacheable = null,
    ) {
        $this->cacheable = $cacheable ?? static fn(ResponseInterface $response): bool
            => $response->getStatusCode() < 500;
    }

    public function process(IdempotencyCall $call, callable $next): mixed
    {
        $executed = false;
        $encoded = $call->withOperation(function (IdempotencyContext $ctx) use (&$executed, $call): mixed {
            $executed = true;
            return $this->encode(($call->operation)($ctx));
        });

        try {
            $cached = $next($encoded);
        } catch (LockedException $e) {
            return $this->conflict($e->lock);
        }

        $result = $this->decode($cached);

        return $result instanceof ResponseInterface && $call->key !== null
            ? $this->decorate($result, $call->key, replayed: !$executed)
            : $result;
    }

    private function encode(mixed $result): mixed
    {
        if (!$result instanceof ResponseInterface) {
            return $result;
        }

        $snapshot = [
            self::SNAPSHOT => true,
            'status' => $result->getStatusCode(),
            'reason' => $result->getReasonPhrase(),
            'headers' => $result->getHeaders(),
            'body' => (string) $result->getBody(),
        ];

        return ($this->cacheable)($result) ? $snapshot : new Uncacheable($snapshot);
    }

    private function decode(mixed $cached): mixed
    {
        if (!\is_array($cached) || ($cached[self::SNAPSHOT] ?? null) !== true) {
            return $cached;
        }

        /** @var array{status: int, reason: string, headers: array<string, list<string>>, body: string} $cached */
        $response = $this->responses->createResponse($cached['status'], $cached['reason']);
        foreach ($cached['headers'] as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response->withBody($this->streams->createStream($cached['body']));
    }

    /**
     * @param non-empty-string $key
     */
    private function decorate(ResponseInterface $result, string $key, bool $replayed): ResponseInterface
    {
        return $result
            ->withHeader('Idempotency-Key', $key)
            ->withHeader('Idempotency-Replay', $replayed ? 'true' : 'false');
    }

    private function conflict(Locked $lock): ResponseInterface
    {
        $payload = (string) \json_encode([
            'error' => 'conflict',
            'message' => 'Request with this idempotency key is already being processed.',
            'retry_after' => $lock->retryAfter,
        ]);

        return $this->responses->createResponse(409)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Retry-After', (string) $lock->retryAfter)
            ->withBody($this->streams->createStream($payload));
    }
}
