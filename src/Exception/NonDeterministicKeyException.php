<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Exception;

/**
 * Thrown by a {@see \Spiral\Idempotency\KeyResolverInterface} when the raw material cannot yield a
 * stable, deterministic key.
 *
 * @api
 */
final class NonDeterministicKeyException extends IdempotencyException
{
}
