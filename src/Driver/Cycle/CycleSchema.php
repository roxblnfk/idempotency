<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Schema\AbstractTable;
use Cycle\Database\Table;
use Spiral\Idempotency\Driver\Cycle\Schema\ColumnDefinition;
use Spiral\Idempotency\Driver\Cycle\Schema\TableDefinition;

/**
 * Declares the lease/inbox tables via the Cycle DBAL schema builder. Intended for tests and
 * bootstrap; production projects usually own migrations (see the Cycle schema generator for an
 * ORM-integrated alternative).
 *
 * The column layout lives in {@see self::lease()} / {@see self::inbox()} so the DBAL path here and
 * the ORM schema generator share one source of truth.
 *
 * @api
 */
final class CycleSchema
{
    /**
     * Backend-neutral definition of the lease table.
     */
    public static function lease(): TableDefinition
    {
        return new TableDefinition(
            columns: [
                new ColumnDefinition('key', 'string', length: 512, primary: true),
                new ColumnDefinition('state', 'string', length: 16),
                new ColumnDefinition('token', 'string', length: 64, nullable: true),
                new ColumnDefinition('success', 'boolean', nullable: true),
                new ColumnDefinition('result', 'text', nullable: true),
                new ColumnDefinition('expire_time', 'bigInteger'),
                new ColumnDefinition('create_time', 'bigInteger'),
            ],
            indexes: [['expire_time']],
        );
    }

    /**
     * Backend-neutral definition of the inbox table. None of the lease columns
     * (state/token/expire_time) are needed — atomicity comes from the transaction.
     */
    public static function inbox(): TableDefinition
    {
        return new TableDefinition(
            columns: [
                new ColumnDefinition('key', 'string', length: 512, primary: true),
                new ColumnDefinition('result', 'text', nullable: true),
                new ColumnDefinition('create_time', 'bigInteger'),
            ],
        );
    }

    /**
     * Create (or sync) the lease table on the given connection.
     *
     * @param non-empty-string $table
     */
    public static function declare(DatabaseInterface $db, string $table = 'idempotency'): void
    {
        self::render(self::schema($db, $table), self::lease());
    }

    /**
     * Create (or sync) the inbox table on the given connection.
     *
     * @param non-empty-string $table
     */
    public static function declareInbox(DatabaseInterface $db, string $table = 'inbox'): void
    {
        self::render(self::schema($db, $table), self::inbox());
    }

    private static function render(AbstractTable $schema, TableDefinition $definition): void
    {
        foreach ($definition->columns as $column) {
            $builder = $schema->column($column->name);
            if ($column->hasLength()) {
                // typed accessor with a size, e.g. ->string(512)
                $builder->{$column->type}($column->length);
            } else {
                $builder->type($column->type);
            }
            $builder->nullable($column->nullable);
        }

        $schema->setPrimaryKeys($definition->primaryKey());

        foreach ($definition->indexes as $columns) {
            $schema->index($columns);
        }

        $schema->save();
    }

    /**
     * @param non-empty-string $table
     */
    private static function schema(DatabaseInterface $db, string $table): AbstractTable
    {
        $handle = $db->table($table);
        if (!$handle instanceof Table) {
            throw new \LogicException(\sprintf(
                'Expected %s, got %s — cannot access the schema builder.',
                Table::class,
                $handle::class,
            ));
        }

        return $handle->getSchema();
    }
}
