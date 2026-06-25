<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Acceptance\Driver\Postgres;

use Spiral\Idempotency\Tests\Acceptance\Common\CycleSchemaTestCase;
use Testo\Filter\Group;
use Testo\Test;

#[Test]
#[Group('driver-pgsql')]
final class SchemaTest extends CycleSchemaTestCase {}
