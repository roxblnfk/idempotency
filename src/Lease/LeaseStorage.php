<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Lease;

/**
 * Storage primitives for the lease branch.
 *
 * Every mutation is a single-roundtrip CAS — the owner check and the mutation happen in ONE storage
 * operation. A PHP-level get()+set() is forbidden (TOCTOU). acquire() is an atomic
 * conditional insert (INSERT ... ON CONFLICT DO NOTHING / SET NX), the serialization point — there is
 * no pre-read. read() is used ONLY in the conflict branch of acquire().
 *
 * abort() and error() do the same thing at the storage level (delete the record); they diverge only
 * in the action the transport takes afterwards.
 *
 * The {@see $result} passed to complete() is the already-serialized opaque blob (string|null);
 * serialization happens above this layer.
 *
 * @api
 */
interface LeaseStorage
{
    /**
     * Atomic conditional insert of a fresh PROCESSING record.
     *
     * @param non-empty-string $key
     * @param non-empty-string $token
     * @param int<1, max> $lockTtl seconds
     * @return bool true if inserted (we own it now); false on conflict (key already present)
     */
    public function acquire(string $key, string $token, int $lockTtl): bool;

    /**
     * CAS by token: PROCESSING → COMPLETED(success), reset TTL to the retention TTL.
     *
     * @param non-empty-string $key
     * @param non-empty-string $token
     * @param int<1, max> $retentionTtl seconds
     * @return bool true if applied; false if we are no longer the owner
     */
    public function complete(string $key, string $token, bool $success, mixed $result, int $retentionTtl): bool;

    /**
     * CAS by token: delete the record, free the key (Failure path).
     *
     * @param non-empty-string $key
     * @param non-empty-string $token
     */
    public function abort(string $key, string $token): bool;

    /**
     * CAS by token: delete the record (Bug path; ERRORED is not persisted).
     *
     * @param non-empty-string $key
     * @param non-empty-string $token
     */
    public function error(string $key, string $token): bool;

    /**
     * CAS by token: extend the PROCESSING lock TTL.
     *
     * Backs {@see LeaseManager::renew()}, which the default pipeline does not call yet (it is
     * reserved for a lock-renewal middleware in the backlog). Implemented and covered per dialect so the
     * primitive is ready.
     *
     * @param non-empty-string $key
     * @param non-empty-string $token
     * @param int<1, max> $lockTtl seconds
     */
    public function renew(string $key, string $token, int $lockTtl): bool;

    /**
     * Read the current record. Used only in the conflict branch of acquire().
     *
     * @param non-empty-string $key
     */
    public function read(string $key): ?StoredEntry;
}
