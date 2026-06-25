<?php

declare(strict_types=1);

namespace Spiral\Idempotency;

use Spiral\Idempotency\Exception\NonDeterministicKeyException;

/**
 * Raw material → final key. One for all transports: normalization, hashing,
 * hierarchical composition `parentKey + stepId`, and determinism validation.
 *
 * Determinism requirement: the final key is a pure function of the input — no
 * random()/now()/autoincrement, otherwise a retry of the same operation cannot recognize itself
 * and deduplication breaks.
 *
 * @api
 */
interface KeyResolverInterface
{
    /**
     * @param string|null $raw raw key material from a {@see KeySourceInterface}
     * @param non-empty-string|null $parentKey parent key for hierarchical composition
     * @return non-empty-string
     * @throws NonDeterministicKeyException if the raw material is unfit for a stable key
     */
    public function resolve(?string $raw, ?string $parentKey = null): string;
}
