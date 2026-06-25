<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Bootloader;

use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Cycle\Bootloader\SchemaBootloader;
use Spiral\Idempotency\Driver\Cycle\Internal\Schema\DefaultSchemaNaming;
use Spiral\Idempotency\Driver\Cycle\Internal\Schema\IdempotencyTablesGenerator;
use Spiral\Idempotency\Driver\Cycle\Schema\SchemaNamingInterface;

/**
 * Opt-in: integrates the Cycle-backed idempotency tables into the application's ORM schema, so they
 * are created/updated by the project's normal `cycle:migrate` / `cycle:sync` workflow instead of a
 * standalone bootstrap step.
 *
 * Register this bootloader only in a Spiral app that uses spiral/cycle-bridge (it depends on the
 * bridge's {@see SchemaBootloader}). Customize the generated role names by binding your own
 * {@see SchemaNamingInterface}.
 *
 * @api
 */
final class CycleSchemaBootloader extends Bootloader
{
    public function defineDependencies(): array
    {
        return [SchemaBootloader::class];
    }

    public function defineBindings(): array
    {
        return [
            SchemaNamingInterface::class => DefaultSchemaNaming::class,
        ];
    }

    public function init(SchemaBootloader $schema): void
    {
        $schema->addGenerator(SchemaBootloader::GROUP_INDEX, IdempotencyTablesGenerator::class);
    }
}
