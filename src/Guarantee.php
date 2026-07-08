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
     * Dedup-guard: the effect runs 0 or 1 times. The marker commits *before* the effect and is never
     * removed, so a duplicate is refused (not re-run) — the mirror image of {@see self::AtLeastOnce}.
     * A crash between marker and effect loses the effect; a repeat is therefore NOT safe to retry.
     */
    case AtMostOnce;

    /**
     * ExactlyOnce *effect*, achievable only when the side-effect and the inbox record commit in a
     * single transaction of one storage. Never exactly-once *delivery* — that is
     * impossible.
     */
    case ExactlyOnce;

    /** The effect runs at least once (retries drive it to success). */
    private const int AT_LEAST = 0b01;

    /** The effect runs at most once (a duplicate is refused). */
    private const int AT_MOST = 0b10;

    /**
     * Guarantees are a 2D lattice, not a linear scale: `≥1` (at-least) and `≤1` (at-most) are
     * independent axes, and ExactlyOnce is their intersection. A bitset of the properties a guarantee
     * carries lets {@see self::satisfies()} express "provided ⊇ required" as a plain mask check.
     *
     * @return int
     */
    private function properties(): int
    {
        return match ($this) {
            self::AtLeastOnce => self::AT_LEAST,
            self::AtMostOnce => self::AT_MOST,
            self::ExactlyOnce => self::AT_LEAST | self::AT_MOST,
        };
    }

    /**
     * Whether a driver providing $this can back a storage alias that declares {@see $required}: it must
     * carry *every* property the alias requires (provided ⊇ required). AtLeastOnce and AtMostOnce are
     * incomparable — only ExactlyOnce satisfies either of them (and itself).
     */
    public function satisfies(self $required): bool
    {
        return ($this->properties() & $required->properties()) === $required->properties();
    }
}
