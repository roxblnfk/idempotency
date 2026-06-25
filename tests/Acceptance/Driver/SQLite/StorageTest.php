<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Acceptance\Driver\SQLite;

use Spiral\Idempotency\Tests\Acceptance\Common\CycleStorageTestCase;
use Testo\Filter\Group;
use Testo\Test;

#[Test]
#[Group('driver-sqlite')]
final class StorageTest extends CycleStorageTestCase {}
