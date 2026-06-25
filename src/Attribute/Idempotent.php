<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Attribute;

/**
 * Marks a handler as idempotent. The method carries a *semantic storage alias*; the
 * concrete driver and the declared guarantee live in config — infra never leaks into
 * business code.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class Idempotent
{
    /**
     * @param non-empty-string $storage semantic alias from config (driver + guarantee live there)
     * @param string|null $key property-path to the key (dot-notation), or null + a custom resolver
     * @param int<1, max>|null $lockTtl override the lock TTL in seconds (AtLeastOnce); null = from config
     * @param int<1, max>|null $ttl override the retention TTL in seconds; null = from config
     */
    public function __construct(
        public string $storage,
        public ?string $key = null,
        public ?int $lockTtl = null,
        public ?int $ttl = null,
    ) {}
}
