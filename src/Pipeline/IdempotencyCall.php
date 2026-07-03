<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Pipeline;

use Spiral\Idempotency\ExecuteOptions;
use Spiral\Idempotency\IdempotencyContext;

/**
 * Immutable context of a single idempotent invocation, flowing through the pipeline.
 *
 * Context of the resolution (outer) pipeline. Carries the transport {@see $context} (PSR-7 request /
 * job payload / event) so the head (transport) middleware can extract the raw key material; the
 * resolved {@see $key} is enriched along the way via {@see self::withKey()}.
 *
 * This is deliberately distinct from the execution (inner) pipeline's context: the inner context never
 * carries {@see $context}, so the domain tail cannot even reach the transport object — the
 * transport-agnostic invariant is enforced by type, not discipline.
 *
 * @api
 */
final readonly class IdempotencyCall
{
    /**
     * @param mixed $context transport object the resolution middleware extract the key from
     * @param \Closure(IdempotencyContext): mixed $operation the business function, run under the guarantee
     * @param non-empty-string|null $key resolved idempotency key; null until a resolver middleware sets it
     */
    public function __construct(
        public mixed $context,
        public \Closure $operation,
        public ExecuteOptions $options,
        public ?string $key = null,
    ) {}

    /**
     * @param non-empty-string $key
     */
    public function withKey(string $key): self
    {
        return new self($this->context, $this->operation, $this->options, $key);
    }

    /**
     * Replace the operation, e.g. to wrap it so its result is encoded before the handler caches it.
     *
     * @param \Closure(IdempotencyContext): mixed $operation
     */
    public function withOperation(\Closure $operation): self
    {
        return new self($this->context, $operation, $this->options, $this->key);
    }
}
