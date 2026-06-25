<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Acceptance\Testo;

use Internal\Container\Container;
use Testo\Common\PluginConfigurator;
use Testo\Pipeline\InterceptorCollector;

/**
 * Wires the multi-driver database harness into the Acceptance suite: one connection pool shared
 * across the suite, and an interceptor that binds the right driver per test case (resolved from the
 * case's `#[Group('driver-<x>')]`) and skips when that database is unreachable.
 */
final readonly class DatabasePlugin implements PluginConfigurator
{
    #[\Override]
    public function configure(Container $container): void
    {
        $container->set(new ConnectionPool());

        // Register by class so the container autowires ConnectionPool (set above) and the Testo
        // Messenger (for SQL logging) into the interceptor.
        $container->get(InterceptorCollector::class)
            ->addInterceptor(DatabaseInterceptor::class);
    }
}
