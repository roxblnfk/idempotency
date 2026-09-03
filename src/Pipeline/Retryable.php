<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Pipeline;

/**
 * Marker: "Infrastructure, a retry is safe". Tag transient exceptions with this so
 * the default classifier routes them to {@see FailureKind::Infrastructure} instead of freezing them
 * as Domain for the retention TTL.
 *
 * @api
 */
interface Retryable
{
}
