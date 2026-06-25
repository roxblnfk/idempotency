<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle\Internal\Schema;

use Cycle\Schema\Definition\Entity;
use Cycle\Schema\Definition\Field;
use Cycle\Schema\GeneratorInterface;
use Cycle\Schema\Registry;
use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\Driver\Cycle\CycleInboxConfig;
use Spiral\Idempotency\Driver\Cycle\CycleLeaseConfig;
use Spiral\Idempotency\Driver\Cycle\CycleSchema;
use Spiral\Idempotency\Driver\Cycle\Schema\ColumnDefinition;
use Spiral\Idempotency\Driver\Cycle\Schema\SchemaNamingInterface;
use Spiral\Idempotency\Driver\Cycle\Schema\TableDefinition;

/**
 * Injects the lease/inbox tables of every Cycle-backed storage into the ORM schema as table-only
 * roles (no entity class). They then flow through the project's normal `cycle:migrate` / `cycle:sync`
 * workflow — no standalone bootstrap command. Non-Cycle storages are ignored.
 *
 * Registered in the INDEX phase so the roles exist before RenderTables runs.
 *
 * @internal Wired by {@see \Spiral\Idempotency\Bootloader\CycleSchemaBootloader}; not public API.
 */
final class IdempotencyTablesGenerator implements GeneratorInterface
{
    public function __construct(
        private readonly IdempotencyConfig $config,
        private readonly SchemaNamingInterface $naming,
    ) {}

    public function run(Registry $registry): Registry
    {
        foreach ($this->config->getStorages() as $alias => $storage) {
            $definition = match (true) {
                $storage instanceof CycleLeaseConfig => CycleSchema::lease(),
                $storage instanceof CycleInboxConfig => CycleSchema::inbox(),
                default => null,
            };

            if ($definition === null) {
                continue;
            }

            /** @var CycleLeaseConfig|CycleInboxConfig $storage */
            $connection = $storage->connection;
            $table = $storage->table;

            $role = $this->naming->role($alias, $storage);
            $registry->register($this->entity($role, $definition));
            $registry->linkTable($registry->getEntity($role), $connection, $table);
        }

        return $registry;
    }

    /**
     * @param non-empty-string $role
     */
    private function entity(string $role, TableDefinition $definition): Entity
    {
        $entity = new Entity();
        $entity->setRole($role);

        foreach ($definition->columns as $column) {
            $entity->getFields()->set($column->name, $this->field($column));
        }

        foreach ($definition->indexes as $index) {
            $entity->addSchemaModifier((new AddIndexModifier($index))->withRole($role));
        }

        return $entity;
    }

    private function field(ColumnDefinition $column): Field
    {
        $field = (new Field())
            ->setType($column->fieldType())
            ->setColumn($column->name)
            ->setPrimary($column->primary);

        if ($column->nullable) {
            $field->getOptions()->set('nullable', true);
        }

        return $field;
    }
}
