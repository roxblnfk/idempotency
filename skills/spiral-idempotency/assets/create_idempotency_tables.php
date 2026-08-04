<?php

declare(strict_types=1);

namespace Migration;

use Cycle\Migrations\Migration;

/**
 * spiral/idempotency tables — a ready-to-copy cycle/migrations artifact.
 *
 * This is the FALLBACK path for projects where the ORM-schema injection
 * (CycleSchemaBootloader + cycle:sync/cycle:migrate) is unavailable or undesired.
 * The column layout mirrors \Spiral\Idempotency\Driver\Cycle\CycleSchema — the package's
 * single source of truth; if this file and CycleSchema disagree, CycleSchema wins.
 *
 * How to adapt before copying into the project's migrations directory:
 *  1. Keep only the tables for storages actually declared in app/config/idempotency.php,
 *     and rename them to match each config's `table:` argument (run scripts/list-storages.php).
 *  2. Set DATABASE to the storage's `connection` (null = the default database).
 *  3. Match the project's migration conventions: this namespace (`Migration` is Spiral's
 *     default), and the filename format `<Ymd.His>_<N>_create_idempotency_tables.php`,
 *     e.g. `20260804.120000_0_create_idempotency_tables.php` — cycle/migrations rejects
 *     other filename shapes. Look at existing files in the migrations dir and copy their style.
 *  4. Run the project's migrate command (`php app.php migrate`).
 */
final class CreateIdempotencyTables extends Migration
{
    protected const DATABASE = null;

    public function up(): void
    {
        // AtLeastOnce lease — one table per CycleLeaseConfig (default table name: 'idempotency').
        $this->table('idempotency_lease')
            ->addColumn('key', 'string', ['size' => 512, 'nullable' => false])
            ->addColumn('state', 'string', ['size' => 16, 'nullable' => false])
            ->addColumn('token', 'string', ['size' => 64, 'nullable' => true])
            ->addColumn('success', 'boolean', ['nullable' => true])
            ->addColumn('result', 'text', ['nullable' => true])
            ->addColumn('expire_time', 'bigInteger', ['nullable' => false])
            ->addColumn('create_time', 'bigInteger', ['nullable' => false])
            ->setPrimaryKeys(['key'])
            ->addIndex(['expire_time']) // GC sweeps by expiry
            ->create();

        // ExactlyOnce inbox — one table per CycleInboxConfig (default table name: 'inbox').
        // No lease columns: atomicity comes from the transaction, not a time-bound lock.
        $this->table('idempotency_inbox')
            ->addColumn('key', 'string', ['size' => 512, 'nullable' => false])
            ->addColumn('result', 'text', ['nullable' => true])
            ->addColumn('create_time', 'bigInteger', ['nullable' => false])
            ->setPrimaryKeys(['key'])
            ->addIndex(['create_time']) // opt-in retention GC deletes index-backed
            ->create();

        // AtMostOnce dedup-guard — one table per CycleAtMostOnceConfig
        // (default table name: 'idempotency_at_most_once'). Same shape as the inbox.
        $this->table('idempotency_at_most_once')
            ->addColumn('key', 'string', ['size' => 512, 'nullable' => false])
            ->addColumn('result', 'text', ['nullable' => true])
            ->addColumn('create_time', 'bigInteger', ['nullable' => false])
            ->setPrimaryKeys(['key'])
            ->addIndex(['create_time'])
            ->create();
    }

    public function down(): void
    {
        $this->table('idempotency_at_most_once')->drop();
        $this->table('idempotency_inbox')->drop();
        $this->table('idempotency_lease')->drop();
    }
}
