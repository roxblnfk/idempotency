<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Exception;

/**
 * Thrown when the idempotency wiring is misconfigured — e.g. a storage alias declares
 * `guarantee: ExactlyOnce` over a driver without transactional capability, a transport has no
 * configured middleware list, or an explicit `#[Idempotent]` key-path resolves to nothing. Fail-fast:
 * surfaced at bootstrap or on the first affected call, never silently altering dedup behaviour.
 *
 * @api
 */
final class MisconfigurationException extends IdempotencyException
{
}
