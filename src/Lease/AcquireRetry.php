<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Lease;

/**
 * The record vanished between the conditional insert and the follow-up read (its lock TTL expired in
 * that gap). The caller should simply retry acquire().
 *
 * @api
 */
final class AcquireRetry implements AcquireResult
{
}
