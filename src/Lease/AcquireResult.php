<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Lease;

/**
 * Discriminated result of {@see LeaseManager::acquire()} — four outcomes instead of
 * "lease or exception". COMPLETED and PROCESSING are fundamentally
 * different situations (replay the cache vs answer 409), so distinguishing them is mandatory.
 *
 * @see Acquired
 * @see AlreadyCompleted
 * @see Locked
 * @see AcquireRetry
 *
 * @api
 */
interface AcquireResult
{
}
