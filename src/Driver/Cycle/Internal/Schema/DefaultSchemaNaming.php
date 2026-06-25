<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle\Internal\Schema;

use Spiral\Idempotency\Config\StorageConfig;
use Spiral\Idempotency\Driver\Cycle\Schema\SchemaNamingInterface;

/**
 * Default role naming: `idempotency:<alias>`. The prefix keeps these synthetic roles clearly
 * namespaced and unlikely to clash with application entities.
 *
 * @internal Bound by {@see \Spiral\Idempotency\Bootloader\CycleSchemaBootloader}; override the
 *           {@see SchemaNamingInterface} binding to customize.
 */
final class DefaultSchemaNaming implements SchemaNamingInterface
{
    public function role(string $alias, StorageConfig $config): string
    {
        return 'idempotency:' . $alias;
    }
}
