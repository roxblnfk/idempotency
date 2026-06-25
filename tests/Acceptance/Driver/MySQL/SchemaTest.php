<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Acceptance\Driver\MySQL;

use Spiral\Idempotency\Tests\Acceptance\Common\CycleSchemaTestCase;
use Testo\Filter\Group;
use Testo\Test;

#[Test]
#[Group('driver-mysql')]
final class SchemaTest extends CycleSchemaTestCase {}
