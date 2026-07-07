<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Unit\Config;

use Spiral\Idempotency\Config\IdempotencyConfig;
use Spiral\Idempotency\Exception\MisconfigurationException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
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

    public function unknownTransportThrows(): void
    {
        // A missing transport section is a misconfiguration (typo / bootloader enabled without config),
        // not an empty pipeline — it must fail fast with a message naming the config path to add.
        $config = new IdempotencyConfig(['transports' => ['queue' => []]]);

        Expect::exception(MisconfigurationException::class)->withMessageContaining('transports.http');

        $config->getTransport('http');
    }

    public function explicitlyEmptyTransportIsAllowed(): void
    {
        // An explicit empty list is the minimal valid setup: the key comes only from the attribute,
        // with no transport middleware.
        $config = new IdempotencyConfig(['transports' => ['http' => []]]);

        Assert::same($config->getTransport('http'), []);
    }
}
