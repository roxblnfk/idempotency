<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Grpc;

use Spiral\Idempotency\Exception\LockedException;
use Spiral\Idempotency\Exception\MissingKeyException;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\Pipeline\IdempotencyCall;
use Spiral\Idempotency\Pipeline\ResolutionMiddleware;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\StatusCode;

/**
 * gRPC outcome middleware: maps the idempotent call to/from a gRPC response. The gRPC analog of
 * {@see \Spiral\Idempotency\Http\HttpOutcomeMiddleware}.
 *
 *  - snapshots the handler's protobuf response message into a serialize()-safe `[class, bytes]` array
 *    before the storage caches it (a protobuf {@see \Google\Protobuf\Internal\Message} does not survive
 *    PHP `serialize()` reliably across the C-extension / pure-PHP runtimes), and rebuilds it via
 *    `mergeFromString()` on the way out (fresh call and replay alike);
 *  - turns a {@see LockedException} (someone else holds PROCESSING for this key) into a
 *    {@see GRPCException} with {@see StatusCode::ABORTED} — the gRPC status the spec recommends for
 *    "retry at a higher level", the analog of the HTTP `409 Conflict`;
 *  - turns a {@see MissingKeyException} into {@see StatusCode::INVALID_ARGUMENT} (a client error, the
 *    analog of HTTP `400 Bad Request`).
 *
 * The gRPC server (`Spiral\RoadRunner\GRPC\Server`) catches `GRPCExceptionInterface` and turns its
 * `getCode()` into the wire status, so throwing {@see GRPCException} here is sufficient. Recommended as
 * the OUTERMOST gRPC middleware so it also maps key-resolution failures raised by the inner key
 * middleware. Non-message results pass through untouched. Uses only `spiral/roadrunner-grpc` /
 * `google/protobuf` types, confined to this transport dir.
 *
 * @api
 */
final readonly class GrpcOutcomeMiddleware implements ResolutionMiddleware
{
    private const SNAPSHOT = '__idempotency_grpc_message__';

    public function process(IdempotencyCall $call, callable $next): mixed
    {
        $encoded = $call->withOperation(
            fn(IdempotencyContext $ctx): mixed => $this->encode(($call->operation)($ctx)),
        );

        try {
            $cached = $next($encoded);
        } catch (LockedException $e) {
            throw new GRPCException(
                \sprintf(
                    'Request with this idempotency key is already being processed (retry after %ds).',
                    $e->lock->retryAfter,
                ),
                StatusCode::ABORTED,
                previous: $e,
            );
        } catch (MissingKeyException $e) {
            throw new GRPCException($e->getMessage(), StatusCode::INVALID_ARGUMENT, previous: $e);
        }

        return $this->decode($cached);
    }

    private function encode(mixed $result): mixed
    {
        if (!$this->isMessage($result)) {
            return $result;
        }

        return [
            self::SNAPSHOT => true,
            'class' => $result::class,
            'bytes' => $result->serializeToString(),
        ];
    }

    private function decode(mixed $cached): mixed
    {
        if (!\is_array($cached) || ($cached[self::SNAPSHOT] ?? null) !== true) {
            return $cached;
        }

        /** @var array{class: class-string, bytes: string} $cached */
        $class = $cached['class'];
        $message = new $class();
        \assert($this->isMessage($message));
        $message->mergeFromString($cached['bytes']);

        return $message;
    }

    /**
     * Duck-type a protobuf message so the middleware works with any protobuf runtime (C-extension or
     * pure-PHP) without a hard compile-time coupling to a generated class. All generated messages extend
     * {@see \Google\Protobuf\Internal\Message} and expose `serializeToString()` / `mergeFromString()`.
     *
     * @psalm-assert-if-true \Google\Protobuf\Internal\Message $result
     */
    private function isMessage(mixed $result): bool
    {
        return \is_object($result)
            && \method_exists($result, 'serializeToString')
            && \method_exists($result, 'mergeFromString');
    }
}
