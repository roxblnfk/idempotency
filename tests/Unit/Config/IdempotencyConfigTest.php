<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Config;

use Spiral\Idempotency\Config\IdempotencyConfig;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(IdempotencyConfig::class)]
final class IdempotencyConfigTest
{
    public function defaultAliasIsExposed(): void
    {
        $config = new IdempotencyConfig(['default' => 'notifications', 'storages' => []]);

        Assert::same($config->getDefault(), 'notifications');
    }
}
