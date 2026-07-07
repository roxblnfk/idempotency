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
     * @param list<list<non-empty-string>> $indexes each entry is a list of column names
     */
    public function __construct(
        public array $columns,
        public array $indexes = [],
    ) {}

    /**
     * Primary-key column names, derived from the columns' {@see ColumnDefinition::$primary} flag — the
     * single source of truth also read by the ORM schema generator.
     *
     * @return list<non-empty-string>
     */
    public function primaryKey(): array
    {
        return \array_values(\array_map(
            static fn(ColumnDefinition $column): string => $column->name,
            \array_filter($this->columns, static fn(ColumnDefinition $column): bool => $column->primary),
        ));
    }
}
