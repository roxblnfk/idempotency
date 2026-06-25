<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Lease;

use Spiral\Idempotency\Exception\LeaseLostException;

/**
 * The lease state-machine over a {@see LeaseStorageInterface}. It gives bare
 * transition primitives — it knows nothing about Domain/Bug/Failure categories, retry or HTTP codes
 * (those live in pipeline middleware).
 *
 * @api
 */
interface LeaseManagerInterface
{
    /**
     * Atomic conditional acquire. Generates a fencing token internally and returns a discriminated
     * result.
     *
     * @param non-empty-string $key
     * @param int<1, max> $lockTtl seconds
     */
    public function acquire(string $key, int $lockTtl): AcquireResult;

    /**
     * Atomic CAS by token: PROCESSING → COMPLETED(success), retention TTL.
     *
     * @param non-empty-string $key
     * @param non-empty-string $token
     * @param int<1, max> $retentionTtl seconds
     * @throws LeaseLostException if the CAS fails because we are no longer the owner
     */
    public function complete(string $key, string $token, bool $success, mixed $result, int $retentionTtl): void;

    /**
     * Failure (infra): delete the record, free the key. CAS by token. Retry is decided by the
     * transport/client.
     *
     * @param non-empty-string $key
     * @param non-empty-string $token
     * @throws LeaseLostException
     */
    public function abort(string $key, string $token): void;

    /**
     * Bug: delete the record (ERRORED is not persisted). CAS by token. No retry; report happens
     * outside.
     *
     * @param non-empty-string $key
     * @param non-empty-string $token
     * @throws LeaseLostException
     */
    public function error(string $key, string $token): void;

    /**
     * Extend PROCESSING. CAS by token.
     *
     * @param non-empty-string $key
     * @param non-empty-string $token
     * @param int<1, max> $lockTtl seconds
     * @throws LeaseLostException
     */
    public function renew(string $key, string $token, int $lockTtl): void;
}
