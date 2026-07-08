<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Bootloader;

use Psr\Container\ContainerInterface;
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Core\BinderInterface;
use Spiral\Core\Config\Proxy;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Spiral\Idempotency\IdempotencyRegistry;
use Spiral\Idempotency\Interceptor\IdempotencyInterceptor;
use Spiral\Idempotency\Interceptor\IdempotencyInterceptorInterface;
use Spiral\Idempotency\KeyResolverInterface;

/**
 * Opt-in HTTP wiring for the idempotency pipeline. No request-scope bindings: the HTTP resolution
 * middleware ({@see \Spiral\Idempotency\Http\HttpKeyMiddleware},
 * {@see \Spiral\Idempotency\Http\HttpOutcomeMiddleware}) are plain autowired services listed in the
 * `transports.http` config stack; the request travels inside the call context, so nothing is
 * per-request-scoped here.
 *
 * The interceptor is exposed under the {@see IdempotencyInterceptorInterface} alias in two layers
 * (the framework's scoped-proxy pattern, cf. `TracerInterface` / `AuthContextInterface`):
 *
 *  - the REAL {@see IdempotencyInterceptor} (flavored with `transport: 'http'`) is a singleton of the
 *    `http` DISPATCHER scope — the scope lives as long as the HTTP dispatcher itself (per-request
 *    state resets in the nested `http-request` scope), so the interceptor and its internal caches
 *    survive across requests;
 *  - the ROOT container holds only a {@see Proxy}: a domain core may be built in any scope
 *    (e.g. a classic `DomainBootloader` root singleton) — every `intercept()` call resolves the real
 *    interceptor from the ACTIVE dispatcher scope at call time. Outside such a scope the proxy fails
 *    fast with a {@see MisconfigurationException} instead of silently picking a wrong transport.
 *    A future queue integration binds its `transport: 'queue'` flavor in the `queue` scope under the
 *    same alias.
 *
 * An app adds `IdempotencyInterceptorInterface::class` to its HTTP domain core's interceptor list and
 * lists the HTTP middleware under `transports.http` in `config/idempotency.php`.
 *
 * The app MUST declare `transports.http` — at least as an empty list. A missing section is treated as a
 * misconfiguration: {@see IdempotencyConfig::getTransport()} throws
 * {@see MisconfigurationException} on the first `#[Idempotent]` call rather
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

    public function defineBindings(): array
    {
        return [
            // Root: a scoped proxy only. Each intercept() forwards to the flavor bound in the active
            // dispatcher scope; the fallback fires when no scope on the chain bound the alias.
            IdempotencyInterceptorInterface::class => new Proxy(
                IdempotencyInterceptorInterface::class,
                false,
                static fn(): never => throw new MisconfigurationException(
                    'IdempotencyInterceptorInterface is used outside of a transport dispatcher scope.',
                    'The real interceptor is bound per transport: HttpIdempotencyBootloader binds the '
                    . 'http flavor inside the `http` scope. Invoke the interceptor while a transport '
                    . 'scope is active, or register the integration bootloader for this transport.',
                ),
            ),
        ];
    }

    public function init(BinderInterface $binder): void
    {
        // The `http` dispatcher scope, not root and not `http-request`: dispatcher-lifetime singleton,
        // and the alias stays free for other transports' scopes.
        $binder->getBinder('http')->bindSingleton(
            IdempotencyInterceptorInterface::class,
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
