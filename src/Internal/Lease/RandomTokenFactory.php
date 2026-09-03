<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Internal\Lease;

use Spiral\Idempotency\Lease\TokenFactory;

/**
 * Default {@see TokenFactory}: 16 random bytes, hex-encoded (32 chars, fits the
 * VARCHAR(64) token column).
 *
 * @internal Bound to {@see TokenFactory} by the bootloader; not part of the public API.
 */
final class RandomTokenFactory implements TokenFactory
{
    public function create(): string
    {
        return \bin2hex(\random_bytes(16));
    }
}
