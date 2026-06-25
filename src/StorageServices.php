<?php

declare(strict_types=1);

namespace Spiral\Idempotency;

use Psr\Clock\ClockInterface;
use Spiral\Idempotency\Lease\TokenFactoryInterface;
use Spiral\Idempotency\Pipeline\FailureClassifierInterface;
use Spiral\Serializer\SerializerInterface;

/**
 * Driver-agnostic runtime services handed to {@see StorageFactoryInterface::create()} so a factory can
 * assemble its driver. Anything backend-specific (a database provider, a Redis client, ...) is NOT here
 * — the factory injects that through its own constructor, since factories are resolved from the
 * container.
 *
 * @api
 */
final readonly class StorageServices
{
    public function __construct(
        public ClockInterface $clock,
        public TokenFactoryInterface $tokens,
        public FailureClassifierInterface $classifier,
        public SerializerInterface $serializer,
    ) {}
}
