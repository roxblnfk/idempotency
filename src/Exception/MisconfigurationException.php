<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Exception;

/**
 * Thrown at bootstrap when a storage alias is misconfigured — e.g. it declares
 * `guarantee: ExactlyOnce` over a driver without transactional capability. Fail-fast.
 *
 * @api
 */
final class MisconfigurationException extends IdempotencyException
{
}
