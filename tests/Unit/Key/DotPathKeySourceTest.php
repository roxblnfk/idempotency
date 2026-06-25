<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Key;

use Spiral\Idempotency\Key\DotPathKeySource;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(DotPathKeySource::class)]
final class DotPathKeySourceTest
{
    public function extractsFromNestedArray(): void
    {
        $source = new DotPathKeySource('payload.transactionId');

        $raw = $source->extract(['payload' => ['transactionId' => 'tx-42']]);

        Assert::same($raw, 'tx-42');
    }

    public function extractsFromObjectGraph(): void
    {
        $event = (object) ['order' => (object) ['id' => 7]];
        $source = new DotPathKeySource('order.id');

        Assert::same($source->extract($event), '7');
    }

    public function returnsNullWhenSegmentMissing(): void
    {
        $source = new DotPathKeySource('payload.missing');

        Assert::null($source->extract(['payload' => ['transactionId' => 'tx-42']]));
    }

    public function returnsNullWhenLeafIsNotScalar(): void
    {
        $source = new DotPathKeySource('payload');

        Assert::null($source->extract(['payload' => ['nested' => 'x']]));
    }

    public function extractsViaArrayAccess(): void
    {
        $access = new class implements \ArrayAccess {
            /** @var array<string, mixed> */
            private array $data = ['id' => 'abc'];

            public function offsetExists(mixed $offset): bool
            {
                return isset($this->data[$offset]);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->data[$offset] ?? null;
            }

            public function offsetSet(mixed $offset, mixed $value): void {}

            public function offsetUnset(mixed $offset): void {}
        };

        Assert::same((new DotPathKeySource('id'))->extract($access), 'abc');
    }
}
