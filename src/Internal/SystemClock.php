<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Internal;

use Psr\Clock\ClockInterface;

/**
 * Default wall-clock used when the application does not bind its own {@see ClockInterface}.
 *
 * @internal Bound to {@see ClockInterface} by the bootloader; not part of the public API.
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }
}
