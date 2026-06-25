<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Driver\Cycle\Schema;

/**
 * Backend-neutral description of a single column. Rendered either onto the DBAL schema builder
 * ({@see \Spiral\Idempotency\Driver\Cycle\CycleSchema}) or onto a Cycle ORM schema field
 * (the schema generator), so the column truth lives in exactly one place.
 *
 * @api
 */
final readonly class ColumnDefinition
{
    /**
     * @param non-empty-string $name
     * @param non-empty-string $type abstract column type: string|text|boolean|bigInteger|...
     * @param positive-int|null $length type length (e.g. string(512)); null when not applicable
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?int $length = null,
        public bool $nullable = false,
        public bool $primary = false,
    ) {}

    /**
     * Cycle ORM field type notation, e.g. "string(512)" or "bigInteger".
     *
     * @return non-empty-string
     */
    public function fieldType(): string
    {
        return $this->length === null ? $this->type : \sprintf('%s(%d)', $this->type, $this->length);
    }
}
