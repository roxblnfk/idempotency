<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Internal\Key;

use Spiral\Idempotency\Exception\MissingKeyException;
use Spiral\Idempotency\KeyResolver;

/**
 * Default resolver: trims the raw material, composes the hierarchy `parentKey + stepId`
 * with a separator, and hashes when the composed key would overflow {@see $maxLength} (or always,
 * when {@see $hashAlways}).
 *
 * Determinism is a caller contract — we cannot detect random()/now() at runtime, so the
 * only enforceable rejection is empty/blank material.
 *
 * @internal Bound to {@see KeyResolver} by the bootloader; not part of the public API.
 */
final class DefaultKeyResolver implements KeyResolver
{
    /**
     * @param non-empty-string $separator hierarchy separator for `parentKey + stepId`
     * @param positive-int $maxLength threshold above which the composed key is hashed
     * @param non-empty-string $hashAlgo algorithm passed to {@see \hash()}
     */
    public function __construct(
        private readonly string $separator = ':',
        private readonly int $maxLength = 512,
        private readonly bool $hashAlways = false,
        private readonly string $hashAlgo = 'sha256',
    ) {}

    public function resolve(?string $raw, ?string $parentKey = null): string
    {
        $material = $raw === null ? '' : \trim($raw);
        if ($material === '') {
            throw new MissingKeyException(
                'Idempotency key material is empty; cannot derive a stable key.',
            );
        }

        $composed = $parentKey === null
            ? $material
            : $parentKey . $this->separator . $material;

        if ($this->hashAlways || \strlen($composed) > $this->maxLength) {
            $composed = \hash($this->hashAlgo, $composed);
        }

        return $composed;
    }
}
