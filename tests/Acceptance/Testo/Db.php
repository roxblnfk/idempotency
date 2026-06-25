<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Acceptance\Testo;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseProviderInterface;

/**
 * Process-global handle to the connection the {@see DatabaseInterceptor} has bound for the current
 * test case. The acceptance base test case reads it back through here (mirrors the static facade the
 * Cycle ActiveRecord harness uses). Set per case, reset in the interceptor's `finally`.
 */
final class Db
{
    private static ?DatabaseProviderInterface $manager = null;

    public static function use(DatabaseProviderInterface $manager): void
    {
        self::$manager = $manager;
    }

    public static function reset(): void
    {
        self::$manager = null;
    }

    public static function isBound(): bool
    {
        return self::$manager !== null;
    }

    public static function manager(): DatabaseProviderInterface
    {
        return self::$manager ?? throw new \LogicException(
            'No database bound. Acceptance tests must run inside the Acceptance suite (DatabasePlugin).',
        );
    }

    public static function database(string $name = 'default'): DatabaseInterface
    {
        return self::manager()->database($name);
    }
}
