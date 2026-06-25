<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Support;

use Psr\Clock\ClockInterface;

/**
 * Test clock with a settable "now" so TTL expiry can be driven deterministically.
 */
final class MutableClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(?\DateTimeImmutable $start = null)
    {
        $this->now = $start ?? new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->add(new \DateInterval('PT' . $seconds . 'S'));
    }
}
