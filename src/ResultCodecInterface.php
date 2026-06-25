<?php

declare(strict_types=1);

namespace Spiral\Idempotency;

/**
 * Marshals a handler's result to/from the cache and decorates it with idempotency metadata.
 *
 * This is the transport-specific seam of the idempotency interceptor: the interceptor itself stays
 * transport-agnostic, while the concrete codec (bound per scope) decides how a result is turned into
 * a serializable form, rebuilt, and annotated. The HTTP codec
 * ({@see \Spiral\Idempotency\Http\HttpResultCodec}) handles PSR-7 responses; the default
 * passthrough codec handles already-serializable values (arrays, DTOs, scalars).
 *
 * @api
 */
interface ResultCodecInterface
{
    /**
     * Convert a handler result into a serializable form for the storage to cache. Called inside the
     * idempotent operation, so its return value is what gets stored.
     */
    public function encode(mixed $result): mixed;

    /**
     * Reconstruct a handler result from its cached form — the inverse of {@see self::encode()}.
     */
    public function decode(mixed $cached): mixed;

    /**
     * Decorate a fresh or replayed result with idempotency metadata (e.g. response headers).
     *
     * @param non-empty-string $key the resolved idempotency key
     * @param bool $replayed true when the result came from cache, false when freshly computed
     */
    public function decorate(mixed $result, string $key, bool $replayed): mixed;
}
