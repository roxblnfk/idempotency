<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Lease;

/**
 * What physically lies in storage, returned by {@see LeaseStorage::read()}.
 *
 * {@see $result} is an OPAQUE string — the core never interprets it; serialization is done one level
 * up (transport/driver middleware). {@see $success} is for non-HTTP consumers, metrics
 * and observability — not for HTTP replay (there the status lives inside the blob).
 *
 * @api
 */
final class StoredEntry
{
    /**
     * @param non-empty-string $key
     * @param non-empty-string|null $token fencing token; null after a terminal transition
     * @param bool|null $success only meaningful when state is COMPLETED
     * @param string|null $result opaque serialized blob; null for void results / PROCESSING
     * @param \DateTimeImmutable $expireTime when the record expires (AIP-142 `*_time` naming)
     */
    public function __construct(
        public readonly string $key,
        public readonly LeaseState $state,
        public readonly ?string $token,
        public readonly ?bool $success,
        public readonly ?string $result,
        public readonly \DateTimeImmutable $expireTime,
    ) {}
}
