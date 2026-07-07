<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Acceptance\Driver\Postgres;

use Cycle\Database\DatabaseInterface;
use Spiral\Idempotency\Tests\Acceptance\Common\InboxConcurrencyTestCase;
use Spiral\Idempotency\Tests\Acceptance\Testo\DatabaseDriver;
use Testo\Filter\Group;
use Testo\Test;

#[Test]
#[Group('driver-pgsql')]
final class InboxConcurrencyTest extends InboxConcurrencyTestCase
{
    #[\Override]
    protected function secondConnection(): DatabaseInterface
    {
        // A brand-new DatabaseManager with the same config yields an independent driver/PDO — a second
        // session against the same database the harness already prepared for connection A.
        return DatabaseDriver::Postgres->manager()->database('default');
    }
}
