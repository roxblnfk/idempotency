<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Grpc;

use Spiral\Idempotency\Exception\LockedException;
use Spiral\Idempotency\Exception\MissingKeyException;
use Spiral\Idempotency\IdempotencyContext;
use Spiral\Idempotency\Internal\Pipeline\DefaultFailureClassifier;
use Spiral\Idempotency\Pipeline\FailureClassifierInterface;
use Spiral\Idempotency\Pipeline\FailureKind;
use Spiral\Idempotency\Pipeline\IdempotencyCall;
use Spiral\Idempotency\Pipeline\ResolutionMiddleware;
use Spiral\Idempotency\Uncacheable;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\Exception\GRPCExceptionInterface;
use Spiral\RoadRunner\GRPC\StatusCode;

/**
 * gRPC outcome middleware: maps the idempotent call to/from a gRPC response. The gRPC analog of
 * {@see \Spiral\Idempotency\Http\HttpOutcomeMiddleware}.
 *
 *  - snapshots the handler's protobuf response message into a serialize()-safe `[class, bytes]` array
 *    before the storage caches it (a protobuf {@see \Google\Protobuf\Internal\Message} does not survive
 *    PHP `serialize()` reliably across the C-extension / pure-PHP runtimes), and rebuilds it via
 *    `mergeFromString()` on the way out (fresh call and replay alike);
 *  - snapshots a thrown gRPC STATUS the same way (code + message + details), so a replay answers with the
 *    identical status instead of a different one — see below;
 *  - turns a {@see LockedException} (someone else holds PROCESSING for this key) into a
 *    {@see GRPCException} with {@see StatusCode::ABORTED} — the gRPC status the spec recommends for
 *    "retry at a higher level", the analog of the HTTP `409 Conflict`;
 *  - turns a {@see MissingKeyException} into {@see StatusCode::INVALID_ARGUMENT} (a client error, the
 *    analog of HTTP `400 Bad Request`).
 *
 * Why statuses are snapshotted: over gRPC a negative outcome is not a response object but a status, and a
 * status travels as a THROW — which bypasses the response snapshot. Without this, the first attempt would
 * answer `FAILED_PRECONDITION` while a replay rethrows a
 * {@see \Spiral\Idempotency\Exception\CachedDomainFailureException} — not a {@see GRPCExceptionInterface}
 * at all, so `Spiral\RoadRunner\GRPC\Server` reports it as a worker error instead of a status. Nothing to
 * configure: unlike HTTP (where the exception → response mapping is application-specific), a gRPC status
 * IS the wire outcome, so `code` + `message` + `details` is a complete, deterministic snapshot. For a
 * service that throws plain domain exceptions instead, bind a {@see DomainFailureMapperInterface}.
 *
 * Transient statuses (see {@see self::TRANSIENT}) are NOT cached: they are wrapped in {@see Uncacheable},
 * so the lease handler releases the key and a retry re-runs, exactly like a 5xx response over HTTP.
 *
 * The gRPC server (`Spiral\RoadRunner\GRPC\Server`) catches `GRPCExceptionInterface` and turns its
 * `getCode()` into the wire status, so throwing {@see GRPCException} here is sufficient. Recommended as
 * the OUTERMOST gRPC middleware so it also maps key-resolution failures raised by the inner key
 * middleware. Non-message results pass through untouched. Uses only `spiral/roadrunner-grpc` /
 * `google/protobuf` types, confined to this transport dir.
 *
 * @psalm-import-type StatusCodeType from StatusCode
 *
 * @api
 */
final readonly class GrpcOutcomeMiddleware implements ResolutionMiddleware
{
    private const SNAPSHOT = '__idempotency_grpc_message__';
    private const STATUS = '__idempotency_grpc_status__';

    /**
     * Statuses that describe a transient condition rather than a decided outcome — the gRPC counterpart of
     * HTTP 5xx: caching one would replay a temporary failure for the whole retention TTL. Everything else
     * (INVALID_ARGUMENT, NOT_FOUND, ALREADY_EXISTS, PERMISSION_DENIED, FAILED_PRECONDITION, OUT_OF_RANGE,
     * UNIMPLEMENTED, UNAUTHENTICATED) is a deterministic answer and is cached.
     *
     * ABORTED is here for symmetry, although this middleware's own Locked → ABORTED is thrown outside the
     * operation and never reaches the snapshot.
     */
    private const TRANSIENT = [
        StatusCode::CANCELLED,
        StatusCode::UNKNOWN,
        StatusCode::DEADLINE_EXCEEDED,
        StatusCode::RESOURCE_EXHAUSTED,
        StatusCode::ABORTED,
        StatusCode::INTERNAL,
        StatusCode::UNAVAILABLE,
        StatusCode::DATA_LOSS,
    ];

    /** @var \Closure(int): bool */
    private \Closure $cacheable;

    private FailureClassifierInterface $classifier;

    /**
     * @param (\Closure(int): bool)|null $cacheable decides whether a thrown status may be cached, by status
     *        code; default: every code except {@see self::TRANSIENT}
     * @param DomainFailureMapperInterface|null $failures maps a thrown domain failure that is not already a
     *        gRPC status into one; null (default) rethrows such a failure untouched
     * @param FailureClassifierInterface|null $classifier decides which throwables are Domain and may
     *        therefore be snapshotted; defaults to {@see DefaultFailureClassifier}. Bind the same
     *        classifier the drivers use (the bootloader does) so both agree on what a domain failure is.
     */
    public function __construct(
        ?\Closure $cacheable = null,
        private ?DomainFailureMapperInterface $failures = null,
        ?FailureClassifierInterface $classifier = null,
    ) {
        $this->cacheable = $cacheable ?? static fn(int $code): bool => !\in_array($code, self::TRANSIENT, true);
        $this->classifier = $classifier ?? new DefaultFailureClassifier();
    }

    public function process(IdempotencyCall $call, callable $next): mixed
    {
        $encoded = $call->withOperation(function (IdempotencyContext $ctx) use ($call): mixed {
            try {
                return $this->encode(($call->operation)($ctx));
            } catch (\Throwable $e) {
                // Rethrows unless the failure is a domain status (or a mapper turned it into one).
                return $this->encodeFailure($e);
            }
        });

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
        return $this->isMessage($result)
            ? [self::SNAPSHOT => true] + $this->encodeMessage($result)
            : $result;
    }

    /**
     * Snapshot a thrown DOMAIN status so the cached outcome carries what the client actually sees, and a
     * replay reproduces it exactly (see the class docblock).
     *
     * Runs INSIDE the operation, i.e. beneath the execution pipeline's classifier middleware, so the kind is
     * decided here by the injected {@see $classifier}. Only Domain is snapshotted: an Infrastructure or Bug
     * failure MUST stay a throwable so the driver frees the key (transport/client retries) or reports it.
     *
     * @return array<string, mixed>|Uncacheable the snapshot, wrapped when the status is transient
     * @throws \Throwable the original failure, when the kind is not Domain, the failure is not a status and
     *         no mapper is bound, or the mapper declined it
     */
    private function encodeFailure(\Throwable $e): array|Uncacheable
    {
        if ($this->classifier->classify($e) !== FailureKind::Domain) {
            throw $e;
        }

        $status = $e instanceof GRPCExceptionInterface ? $e : $this->failures?->map($e);
        if ($status === null) {
            throw $e;
        }

        $code = $status->getCode();
        $snapshot = [
            self::STATUS => true,
            'class' => $status::class,
            'code' => $code,
            'message' => $status->getMessage(),
            // Details are protobuf messages (the google.rpc.* error model) — snapshot each like a response.
            'details' => \array_values(\array_map($this->encodeMessage(...), $status->getDetails())),
        ];

        return ($this->cacheable)($code) ? $snapshot : new Uncacheable($snapshot);
    }

    private function decode(mixed $cached): mixed
    {
        if (!\is_array($cached)) {
            return $cached;
        }

        if (($cached[self::STATUS] ?? null) === true) {
            /** @var array{class: string, code: int, message: string, details: list<array{class: class-string, bytes: string}>} $cached */
            throw $this->rebuildStatus($cached);
        }

        if (($cached[self::SNAPSHOT] ?? null) !== true) {
            return $cached;
        }

        /** @var array{class: class-string, bytes: string} $cached */
        return $this->decodeMessage($cached);
    }

    /**
     * @param array{class: string, code: int, message: string, details: list<array{class: class-string, bytes: string}>} $cached
     */
    private function rebuildStatus(array $cached): GRPCExceptionInterface
    {
        $details = \array_map($this->decodeMessage(...), $cached['details']);
        $class = $cached['class'];
        $code = $this->statusCode($cached['code']);

        // Every GRPCException subclass shares ONE final constructor, so the exact type is safe to rebuild
        // (and lets in-process catch-by-type keep working). A foreign GRPCExceptionInterface implementation
        // has an unknown signature — fall back to a plain GRPCException, which is wire-identical: code +
        // message + details is all the client observes. is_a() with a vanished class returns false, so a
        // class removed between deployments degrades instead of fataling.
        /** @var \Google\Protobuf\Internal\Message[] $details */
        return \is_a($class, GRPCException::class, true)
            ? new $class($cached['message'], $code, $details)
            : new GRPCException($cached['message'], $code, $details);
    }

    /**
     * Narrow a status code read back from storage: the gRPC codes are the contiguous `0..16` range, and a
     * foreign or corrupted row must not put an out-of-range status on the wire.
     *
     * @return StatusCodeType
     */
    private function statusCode(int $code): int
    {
        /** @var StatusCodeType */
        return $code >= StatusCode::OK && $code <= StatusCode::UNAUTHENTICATED ? $code : StatusCode::UNKNOWN;
    }

    /**
     * @return array{class: class-string, bytes: string}
     */
    private function encodeMessage(object $message): array
    {
        \assert($this->isMessage($message));

        return ['class' => $message::class, 'bytes' => $message->serializeToString()];
    }

    /**
     * @param array{class: class-string, bytes: string} $snapshot
     */
    private function decodeMessage(array $snapshot): object
    {
        $class = $snapshot['class'];
        $message = new $class();
        \assert($this->isMessage($message));
        $message->mergeFromString($snapshot['bytes']);

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
