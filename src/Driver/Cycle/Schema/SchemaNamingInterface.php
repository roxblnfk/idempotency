<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle\Schema;

use Spiral\Idempotency\Config\StorageConfig;

/**
 * Customizes the names the schema generator produces for the synthetic Cycle ORM roles that back
 * the idempotency tables. Table names themselves come from each storage's config — this only
 * controls the *role* (which must be unique across the whole ORM schema and not collide with the
 * application's own entities).
 *
 * Bind your own implementation to override; the default is
 * {@see \Spiral\Idempotency\Driver\Cycle\Internal\Schema\DefaultSchemaNaming}.
 *
 * @api
 */
interface SchemaNamingInterface
{
    /**
     * @param non-empty-string $alias storage alias from config
     * @return non-empty-string ORM role for the storage's synthetic schema entity
     */
    public function role(string $alias, StorageConfig $config): string;
}
