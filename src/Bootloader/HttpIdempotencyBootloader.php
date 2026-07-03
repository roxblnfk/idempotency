<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Bootloader;

use Spiral\Boot\Bootloader\Bootloader;

/**
 * Opt-in HTTP wiring for the idempotency pipeline. No scope bindings any more: the HTTP resolution
 * middleware ({@see \Spiral\Idempotency\Http\HttpKeyMiddleware},
 * {@see \Spiral\Idempotency\Http\HttpOutcomeMiddleware}) are plain autowired services listed in the
 * `transports.http` config stack; the request travels inside the call context, so nothing is
 * per-request-scoped here.
 *
 * Register alongside {@see IdempotencyBootloader} in an app that exposes #[Idempotent] over HTTP, add
 * the HTTP middleware to `transports.http` in `config/idempotency.php`, and add an
 * {@see \Spiral\Idempotency\Interceptor\IdempotencyInterceptor} (transport: 'http') to the HTTP domain
 * core's interceptor list.
 *
 * @api
 */
final class HttpIdempotencyBootloader extends Bootloader
{
    public function defineDependencies(): array
    {
        return [IdempotencyBootloader::class];
    }
}
