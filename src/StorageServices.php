<?php

declare(strict_types=1);

namespace Spiral\Idempotency;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Spiral\Idempotency\Lease\TokenFactory;
use Spiral\Idempotency\Pipeline\FailureClassifier;
use Spiral\Serializer\SerializerInterface;

/**
 * Driver-agnostic runtime services handed to {@see StorageFactory::create()} so a factory can
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
        public TokenFactory $tokens,
        public FailureClassifier $classifier,
        public SerializerInterface $serializer,
        public ?LoggerInterface $logger = null,
    ) {}
}
