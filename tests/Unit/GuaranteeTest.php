<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit;

use Spiral\Idempotency\Guarantee;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The full truth table of the 2D guarantee lattice: {@see Guarantee::satisfies()} answers whether a
 * driver providing one guarantee can back an alias declaring another (provided ⊇ required). AtLeastOnce
 * (`≥1`) and AtMostOnce (`≤1`) are incomparable axes; ExactlyOnce is their intersection and the only
 * guarantee that satisfies either of the others.
 */
#[Test]
#[Covers(Guarantee::class)]
final class GuaranteeTest
{
    public function atLeastOnceSatisfiesOnlyAtLeastOnce(): void
    {
        Assert::true(Guarantee::AtLeastOnce->satisfies(Guarantee::AtLeastOnce));
        Assert::false(Guarantee::AtLeastOnce->satisfies(Guarantee::AtMostOnce));
        Assert::false(Guarantee::AtLeastOnce->satisfies(Guarantee::ExactlyOnce));
    }

    public function atMostOnceSatisfiesOnlyAtMostOnce(): void
    {
        Assert::false(Guarantee::AtMostOnce->satisfies(Guarantee::AtLeastOnce));
        Assert::true(Guarantee::AtMostOnce->satisfies(Guarantee::AtMostOnce));
        Assert::false(Guarantee::AtMostOnce->satisfies(Guarantee::ExactlyOnce));
    }

    public function exactlyOnceSatisfiesEveryGuarantee(): void
    {
        Assert::true(Guarantee::ExactlyOnce->satisfies(Guarantee::AtLeastOnce));
        Assert::true(Guarantee::ExactlyOnce->satisfies(Guarantee::AtMostOnce));
        Assert::true(Guarantee::ExactlyOnce->satisfies(Guarantee::ExactlyOnce));
    }
}
