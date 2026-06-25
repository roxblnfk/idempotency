<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle\Schema;

/**
 * Backend-neutral description of one idempotency table (columns + primary key + indexes).
 * Single source of truth shared by the DBAL bootstrap ({@see \Spiral\Idempotency\Driver\Cycle\CycleSchema})
 * and the Cycle ORM schema generator.
 *
 * @api
 */
final readonly class TableDefinition
{
    /**
     * @param list<ColumnDefinition> $columns
     * @param list<non-empty-string> $primaryKey
     * @param list<list<non-empty-string>> $indexes each entry is a list of column names
     */
    public function __construct(
        public array $columns,
        public array $primaryKey,
        public array $indexes = [],
    ) {}
}
