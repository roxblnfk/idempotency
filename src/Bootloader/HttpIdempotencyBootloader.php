<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Bootloader;

use Psr\Container\ContainerInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Interceptor\IdempotencyInterceptor;
use Spiral\Idempotency\KeyResolverInterface;

/**
 * Opt-in HTTP wiring for the idempotency pipeline. No scope bindings any more: the HTTP resolution
 * middleware ({@see \Spiral\Idempotency\Http\HttpKeyMiddleware},
 * {@see \Spiral\Idempotency\Http\HttpOutcomeMiddleware}) are plain autowired services listed in the
 * `transports.http` config stack; the request travels inside the call context, so nothing is
 * per-request-scoped here.
 *
 * Binds {@see IdempotencyInterceptor} to the `http` transport so an app can simply add
 * `IdempotencyInterceptor::class` to its HTTP domain core's interceptor list. List the HTTP middleware
 * under `transports.http` in `config/idempotency.php`.
 *
 * @api
 */
final class HttpIdempotencyBootloader extends Bootloader
{
    public function defineDependencies(): array
    {
        return [IdempotencyBootloader::class];
    }

    public function defineSingletons(): array
    {
        return [
            IdempotencyInterceptor::class => static fn(
                IdempotencyRegistry $registry,
                KeyResolverInterface $keys,
                ContainerInterface $container,
                IdempotencyConfig $config,
            ): IdempotencyInterceptor => new IdempotencyInterceptor(
                $registry,
                $keys,
                $container,
                $config,
                transport: 'http',
            ),
        ];
    }
}
