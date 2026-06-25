<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Key;

use Spiral\Idempotency\KeySourceInterface;

/**
 * Extracts raw key material by a dot-path over an array / object / ArrayAccess graph. Suitable for
 * Jobs (path over the job payload) and Events (path over the event object)
 *
 * Example: `new DotPathKeySource('payload.transactionId')`.
 *
 * @api
 */
final readonly class DotPathKeySource implements KeySourceInterface
{
    /**
     * @param non-empty-string $path dot-notation path to the key value
     */
    public function __construct(
        private string $path,
    ) {}

    public function extract(mixed $endpointContext): ?string
    {
        $current = $endpointContext;
        foreach (\explode('.', $this->path) as $segment) {
            $current = $this->step($current, $segment);
            if ($current === null) {
                return null;
            }
        }

        if (\is_scalar($current) || $current instanceof \Stringable) {
            return (string) $current;
        }

        return null;
    }

    private function step(mixed $node, string $segment): mixed
    {
        return match (true) {
            \is_array($node) => $node[$segment] ?? null,
            $node instanceof \ArrayAccess => $node->offsetExists($segment) ? $node[$segment] : null,
            \is_object($node) => $node->{$segment} ?? null,
            default => null,
        };
    }
}
