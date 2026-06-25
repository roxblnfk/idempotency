<?php

declare(strict_types=1);

namespace Spiral\Idempotency;

/**
 * Declared idempotency guarantee of a storage alias.
 *
 * The guarantee is not statically provable for a given handler — it depends on what the
 * operation does inside (whether its side-effect is transactional). It is therefore declared
 * on the storage alias in configuration, and verified only against the driver's capability.
 *
 * @api
 */
enum Guarantee
{
    /**
     * Always achievable. Lease + dedup key; the side-effect may repeat inside the crash window.
     */
    case AtLeastOnce;

    /**
     * ExactlyOnce *effect*, achievable only when the side-effect and the inbox record commit in a
     * single transaction of one storage. Never exactly-once *delivery* — that is
     * impossible.
     */
    case ExactlyOnce;

    /**
     * Relative strength (higher = stronger). ExactlyOnce subsumes AtLeastOnce.
     *
     * @return int<0, max>
     */
    public function strength(): int
    {
        return match ($this) {
            self::AtLeastOnce => 0,
            self::ExactlyOnce => 1,
        };
    }

    /**
     * Whether a driver providing $this can back a storage alias that declares {@see $required}.
     */
    public function satisfies(self $required): bool
    {
        return $this->strength() >= $required->strength();
    }
}
