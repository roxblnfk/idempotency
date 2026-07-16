<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Internal;

/**
 * Default no-op {@see \Spiral\Idempotency\IdempotencyContext::renew()} for drivers without a renewable
 * PROCESSING lock — the inbox owns a DB transaction (the lock IS the transaction), at-most-once is
 * fire-once. Lets generic operation code call `$ctx->renew()` regardless of the backing storage.
 *
 * @internal
 */
trait NoRenewal
{
    public function renew(bool $force = false): void {}
}
