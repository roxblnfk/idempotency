<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Acceptance\Testo;

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\DriverConfig;
use Cycle\Database\Config\MySQL\TcpConnectionConfig as MySQLConnection;
use Cycle\Database\Config\MySQLDriverConfig;
use Cycle\Database\Config\Postgres\TcpConnectionConfig as PostgresConnection;
use Cycle\Database\Config\PostgresDriverConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseManager;

/**
 * The driver matrix for the Acceptance suite. Each case maps a `driver-<value>` group to a concrete
 * connection config (overridable via DB_* env vars; defaults match tests/docker-compose.yml). One
 * logical database `default` is enough — these storages never span connections.
 */
enum DatabaseDriver: string
{
    case SQLite = 'sqlite';
    case Postgres = 'pgsql';
    case MySQL = 'mysql';

    /**
     * Resolve a `#[Group('driver-<value>')]` label to a driver, or null for any other group.
     */
    public static function fromGroup(string $group): ?self
    {
        return \str_starts_with($group, 'driver-')
            ? self::tryFrom(\substr($group, \strlen('driver-')))
            : null;
    }

    public function manager(): DatabaseManager
    {
        return new DatabaseManager(new DatabaseConfig([
            'default' => 'default',
            'databases' => ['default' => ['connection' => 'default']],
            'connections' => ['default' => $this->driverConfig()],
        ]));
    }

    private function driverConfig(): DriverConfig
    {
        return match ($this) {
            self::SQLite => new SQLiteDriverConfig(
                connection: new MemoryConnectionConfig(),
                queryCache: true,
                options: ['logInterpolatedQueries' => true],
            ),
            self::Postgres => new PostgresDriverConfig(
                connection: new PostgresConnection(
                    database: self::env('DB_DATABASE', 'spiral'),
                    host: self::env('DB_HOST', '127.0.0.1'),
                    port: (int) self::env('DB_PORT', '15432'),
                    user: self::env('DB_USER', 'postgres'),
                    password: self::env('DB_PASSWORD', 'YourStrong!Passw0rd'),
                ),
                schema: 'public',
                queryCache: true,
                options: ['logInterpolatedQueries' => true],
            ),
            self::MySQL => new MySQLDriverConfig(
                connection: new MySQLConnection(
                    database: self::env('DB_DATABASE', 'spiral'),
                    host: self::env('DB_HOST', '127.0.0.1'),
                    port: (int) self::env('DB_PORT', '13306'),
                    user: self::env('DB_USER', 'root'),
                    password: self::env('DB_PASSWORD', 'YourStrong!Passw0rd'),
                ),
                queryCache: true,
                options: ['logInterpolatedQueries' => true],
            ),
        };
    }

    private static function env(string $name, string $default): string
    {
        $value = \getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }
}
