<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Internal;

use Spiral\Idempotency\ResultCodecInterface;

/**
 * Default codec: the result is already serializable (array, DTO, scalar), so encode/decode are
 * identity and there is no metadata to attach. Bound in the root scope; transports that return
 * non-serializable results (e.g. PSR-7 responses over HTTP) override it in their own scope.
 *
 * @internal Bound to {@see ResultCodecInterface} by the bootloader; not part of the public API.
 */
final class PassthroughResultCodec implements ResultCodecInterface
{
    public function encode(mixed $result): mixed
    {
        return $result;
    }

    public function decode(mixed $cached): mixed
    {
        return $cached;
    }

    public function decorate(mixed $result, string $key, bool $replayed): mixed
    {
        return $result;
    }
}
