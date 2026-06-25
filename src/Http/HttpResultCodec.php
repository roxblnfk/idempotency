<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Spiral\Idempotency\ResultCodecInterface;

/**
 * HTTP implementation of {@see ResultCodecInterface}: all PSR-7 specifics live here, not in the
 * interceptor. Bound into the HTTP request scope.
 *
 *  - encode(): a PSR-7 response → a serializable snapshot {status, reason, headers, body};
 *  - decode(): the snapshot → a rebuilt PSR-7 response (via PSR-17 factories);
 *  - decorate(): adds the `Idempotency-Key` / `Idempotency-Replay` response headers.
 *
 * Non-response results pass through untouched (an action may return a serializable value that the
 * HTTP layer renders later).
 *
 * @api
 */
final class HttpResultCodec implements ResultCodecInterface
{
    private const SNAPSHOT = '__idempotency_http_response__';

    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function encode(mixed $result): mixed
    {
        if (!$result instanceof ResponseInterface) {
            return $result;
        }

        return [
            self::SNAPSHOT => true,
            'status' => $result->getStatusCode(),
            'reason' => $result->getReasonPhrase(),
            'headers' => $result->getHeaders(),
            'body' => (string) $result->getBody(),
        ];
    }

    public function decode(mixed $cached): mixed
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

    public function decorate(mixed $result, string $key, bool $replayed): mixed
    {
        if (!$result instanceof ResponseInterface) {
            return $result;
        }

        return $result
            ->withHeader('Idempotency-Key', $key)
            ->withHeader('Idempotency-Replay', $replayed ? 'true' : 'false');
    }
}
