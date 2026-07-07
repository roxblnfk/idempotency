<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Driver\Cycle\Schema;

use Spiral\Idempotency\Driver\Cycle\Schema\ColumnDefinition;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ColumnDefinition::class)]
final class ColumnDefinitionTest
{
    public function lengthOnUnsizedTypeIsRejected(): void
    {
        // ->boolean(512) would silently drop the length on the DBAL builder; forbid it at construction so
        // a definition bug fails loudly instead of producing a wrong column type.
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('does not take a length');

        new ColumnDefinition('flag', 'boolean', length: 512);
    }

    public function stringWithLengthHasLength(): void
    {
        $column = new ColumnDefinition('key', 'string', length: 512);

        Assert::true($column->hasLength());
        Assert::same($column->fieldType(), 'string(512)');
    }

    public function unsizedTypeHasNoLength(): void
    {
        $column = new ColumnDefinition('create_time', 'bigInteger');

        Assert::false($column->hasLength());
        Assert::same($column->fieldType(), 'bigInteger');
    }
}
