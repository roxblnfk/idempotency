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
     * Abstract types that take a length argument (e.g. `->string(512)`). Any other type with a length is
     * a definition bug — an unsized accessor would silently drop it — so the constructor rejects it.
     *
     * @var list<non-empty-string>
     */
    private const SIZED_TYPES = ['string'];

    /**
     * @param non-empty-string $name
     * @param non-empty-string $type abstract column type: string|text|boolean|bigInteger|...
     * @param positive-int|null $length type length (e.g. string(512)); null when not applicable
     * @throws \InvalidArgumentException when a length is given for a type that takes none
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?int $length = null,
        public bool $nullable = false,
        public bool $primary = false,
    ) {
        if ($length !== null && !\in_array($type, self::SIZED_TYPES, true)) {
            throw new \InvalidArgumentException(\sprintf(
                'Column "%s": type "%s" does not take a length; sized types are: %s.',
                $name,
                $type,
                \implode(', ', self::SIZED_TYPES),
            ));
        }
    }

    /**
     * Whether this column's type takes a length argument (and one was supplied), so callers pick the
     * sized accessor (`->string(512)`) over the plain one.
     */
    public function hasLength(): bool
    {
        return $this->length !== null && \in_array($this->type, self::SIZED_TYPES, true);
    }

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
