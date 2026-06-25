<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Lease;

/**
 * The two persisted states of a lease record. There is no long-lived FAILED state:
 * abort()/error() delete the record.
 *
 * @api
 */
enum LeaseState: string
{
    /** Held by an in-flight owner; lives for the (short) lock TTL. */
    case Processing = 'PROCESSING';

    /** Terminal success/domain-failure; lives for the (long) retention TTL, replayed on repeat. */
    case Completed = 'COMPLETED';
}
