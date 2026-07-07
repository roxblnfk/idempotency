<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Bootloader;

use Psr\Container\ContainerInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Core\BinderInterface;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Interceptor\IdempotencyInterceptor;
use Spiral\Idempotency\KeyResolverInterface;

/**
 * Opt-in HTTP wiring for the idempotency pipeline. No request-scope bindings: the HTTP resolution
 * middleware ({@see \Spiral\Idempotency\Http\HttpKeyMiddleware},
 * {@see \Spiral\Idempotency\Http\HttpOutcomeMiddleware}) are plain autowired services listed in the
 * `transports.http` config stack; the request travels inside the call context, so nothing is
 * per-request-scoped here.
 *
 * Binds {@see IdempotencyInterceptor} (flavored with `transport: 'http'`) as a singleton of the `http`
 * DISPATCHER scope — the scope lives as long as the HTTP dispatcher itself (per-request state resets in
 * the nested `http-request` scope instead), so the interceptor and its internal caches survive across
 * requests. The class name is deliberately NOT bound in the root container: the interceptor is
 * transport-parameterized, and each transport's bootloader claims the class inside its own dispatcher
 * scope (a future queue integration binds a `transport: 'queue'` flavor in the `queue` scope). Resolving
 * it in a scope where no integration bound it fails fast on the unresolvable `$transport` argument
 * instead of silently picking a wrong transport.
 *
 * An app adds `IdempotencyInterceptor::class` to its HTTP domain core's interceptor list and lists the
 * HTTP middleware under `transports.http` in `config/idempotency.php`.
 *
 * The app MUST declare `transports.http` — at least as an empty list. A missing section is treated as a
 * misconfiguration: {@see IdempotencyConfig::getTransport()} throws
 * {@see \Spiral\Idempotency\Exception\MisconfigurationException} on the first `#[Idempotent]` call rather
 * than running an empty pipeline that silently disables idempotency. An explicit `'http' => []` is valid
 * (key comes only from the attribute, no HTTP middleware).
 *
 * @api
 */
final class HttpIdempotencyBootloader extends Bootloader
{
    public function defineDependencies(): array
    {
        return [IdempotencyBootloader::class];
    }

    public function init(BinderInterface $binder): void
    {
        // The `http` dispatcher scope, not root and not `http-request`: dispatcher-lifetime singleton,
        // and the transport-parameterized class name stays free for other transports' scopes.
        $binder->getBinder('http')->bindSingleton(
            IdempotencyInterceptor::class,
            static fn(
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
        );
    }
}
