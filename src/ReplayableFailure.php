<?php

declare(strict_types=1);

namespace Spiral\Idempotency;

/**
 * Opt-in contract for a domain exception that can be faithfully re-thrown on idempotent replay.
 *
 * Without this interface, a domain failure is replayed as a {@see \Spiral\Idempotency\Exception\CachedDomainFailureException}
 * — a different type than the one originally thrown — so an application exception handler renders a
 * different HTTP status on the replay than on the first attempt. Implement this interface on exceptions
 * whose exact type must survive a replay (e.g. so the handler maps them to the same status code).
 *
 * The payload MUST be a JSON-safe scalar array (no objects, closures or resources): it is round-tripped
 * through the storage serializer, and a future switch to a JSON serializer must not break the contract.
 * Keeping the payload schema compatible across deployments (a replay may hit a newer worker than the one
 * that stored it) is the responsibility of the exception author.
 *
 * @api
 */
interface ReplayableFailure extends \Throwable
{
    /**
     * The minimal, JSON-safe state needed to reconstruct this failure on replay.
     *
     * @return array<string, scalar|null>
     */
    public function toReplayPayload(): array;

    /**
     * Reconstruct the failure from a payload previously produced by {@see toReplayPayload()}.
     *
     * @param array<string, scalar|null> $payload
     */
    public static function fromReplayPayload(array $payload): static;
}
