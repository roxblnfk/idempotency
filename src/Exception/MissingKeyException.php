<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Exception;

/**
 * No idempotency key was supplied by the client and none could be derived. Transport middleware maps
 * this to a client error (HTTP 400).
 *
 * @api
 */
final class MissingKeyException extends IdempotencyException
{
}
