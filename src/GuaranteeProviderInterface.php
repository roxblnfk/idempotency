<?php

declare(strict_types=1);

namespace Spiral\Idempotency;

/**
 * Implemented by drivers to advertise the strongest guarantee they can actually deliver. Lets the
 * registry fail-fast when a storage alias declares a guarantee the driver cannot back.
 *
 * @api
 */
interface GuaranteeProviderInterface
{
    public function guarantee(): Guarantee;
}
