<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Exception;

/**
 * Thrown by a {@see \Spiral\Idempotency\KeyResolver} when the raw material is present but
 * cannot yield a stable, deterministic key. Reserved for future determinism validation — absent or
 * blank material is a client error and raises {@see MissingKeyException} instead.
 *
 * @api
 */
final class NonDeterministicKeyException extends IdempotencyException
{
}
