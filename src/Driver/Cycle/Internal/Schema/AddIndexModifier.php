<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle\Internal\Schema;

use Cycle\Schema\Registry;
use Cycle\Schema\SchemaModifierInterface;

/**
 * Adds a plain index to a synthetic idempotency role's table. Runs in the RENDER phase (after
 * columns exist), via the schema-builder modifier pipeline.
 *
 * @internal Attached by {@see IdempotencyTablesGenerator}; not part of the public API.
 */
final class AddIndexModifier implements SchemaModifierInterface
{
    /** @var non-empty-string */
    private string $role = 'idempotency';

    /**
     * @param list<non-empty-string> $columns
     */
    public function __construct(
        private readonly array $columns,
    ) {}

    public function withRole(string $role): static
    {
        $clone = clone $this;
        $clone->role = $role;

        return $clone;
    }

    public function compute(Registry $registry): void
    {
        // Nothing to compute: the columns are declared by the generator's fields.
    }

    public function render(Registry $registry): void
    {
        if ($this->columns === []) {
            return;
        }

        $entity = $registry->getEntity($this->role);
        $registry->getTableSchema($entity)->index($this->columns);
    }

    public function modifySchema(array &$schema): void
    {
        // No ORM-schema changes needed.
    }
}
