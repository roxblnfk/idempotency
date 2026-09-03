<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Grpc;

use Spiral\RoadRunner\GRPC\Exception\GRPCExceptionInterface;

/**
 * Maps a DOMAIN failure that is not already a gRPC status into one, so {@see GrpcOutcomeMiddleware} can
 * snapshot it and reproduce the identical status on an idempotent replay.
 *
 * Usually NOT needed: a service method that expresses negative outcomes the gRPC way — by throwing a
 * {@see \Spiral\RoadRunner\GRPC\Exception\GRPCException} — is snapshotted automatically, because the
 * status code, message and details ARE the client-visible outcome. Bind this interface only when the
 * service throws plain domain exceptions (its own, or a library's) and something above the interceptor
 * converts them into statuses — that conversion happens too late to be cached, so the first attempt and
 * the replay would answer with different statuses.
 *
 * Only failures classified as {@see \Spiral\Idempotency\Pipeline\FailureKind::Domain} are passed here.
 * Infrastructure and Bug failures are never mapped — they must stay exceptions so the driver releases the
 * key (a retry re-runs) instead of caching a transient error as the outcome.
 *
 * Return `null` for failures this mapper does not own: they are rethrown untouched.
 *
 * @api
 */
interface DomainFailureMapper
{
    /**
     * @param \Throwable $failure a failure classified as Domain
     * @return GRPCExceptionInterface|null the status to cache and replay, or null to rethrow the failure
     */
    public function map(\Throwable $failure): ?GRPCExceptionInterface;
}
