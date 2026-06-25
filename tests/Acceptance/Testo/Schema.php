<?php

declare(strict_types=1);

namespace Spiral\Idempotency\Tests\Acceptance\Testo;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Table;
use Spiral\Idempotency\Driver\Cycle\CycleSchema;

/**
 * Creates the tables the storage acceptance tests need — once per driver. Reuses the component's own
 * {@see CycleSchema} so the test schema can't drift from the shipped definition. The `ledger` table
 * is a stand-in side-effect target for the inbox transaction tests; rows are tagged with the test's
 * unique key so each test counts only its own.
 *
 * Tables are cleared once here (not between tests): a per-test unique key isolates tests WITHIN a run,
 * and this one-time truncate gives a clean slate ACROSS runs against a persistent dev database
 * (otherwise rows committed by a previous run would be replayed from the dedup cache).
 */
final class Schema
{
    public static function prepare(DatabaseInterface $db): void
    {
        CycleSchema::declare($db, 'idempotency'); // lease (AtLeastOnce)
        CycleSchema::declareInbox($db, 'inbox');  // inbox (ExactlyOnce)
        self::ledger($db);

        $db->delete('idempotency')->run();
        $db->delete('inbox')->run();
        $db->delete('ledger')->run();
    }

    private static function ledger(DatabaseInterface $db): void
    {
        $handle = $db->table('ledger');
        \assert($handle instanceof Table);

        $schema = $handle->getSchema();
        $schema->column('note')->string(255);
        $schema->save();
    }
}
