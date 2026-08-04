-- spiral/idempotency tables — plain-SQL DDL for projects whose migration tooling is not
-- cycle/migrations (Doctrine, Phinx, hand-run SQL, ...). LAST-RESORT fallback: prefer
-- CycleSchemaBootloader + cycle:sync/cycle:migrate, then the cycle/migrations artifact
-- (create_idempotency_tables.php) — see references/setup.md, step 5.
--
-- Mirrors \Spiral\Idempotency\Driver\Cycle\CycleSchema (the source of truth). Before running:
--   * keep only the tables for storages declared in app/config/idempotency.php, and rename
--     them to match each config's `table:` argument (run scripts/list-storages.php);
--   * apply the dialect notes at the bottom.

-- AtLeastOnce lease — one table per CycleLeaseConfig (default table name: 'idempotency').
CREATE TABLE idempotency_lease (
    "key"       VARCHAR(512) NOT NULL,
    state       VARCHAR(16)  NOT NULL,
    token       VARCHAR(64)  NULL,
    success     BOOLEAN      NULL,
    result      TEXT         NULL,
    expire_time BIGINT       NOT NULL,
    create_time BIGINT       NOT NULL,
    PRIMARY KEY ("key")
);
CREATE INDEX idempotency_lease_expire_time_index ON idempotency_lease (expire_time);

-- ExactlyOnce inbox — one table per CycleInboxConfig (default table name: 'inbox').
CREATE TABLE idempotency_inbox (
    "key"       VARCHAR(512) NOT NULL,
    result      TEXT         NULL,
    create_time BIGINT       NOT NULL,
    PRIMARY KEY ("key")
);
CREATE INDEX idempotency_inbox_create_time_index ON idempotency_inbox (create_time);

-- AtMostOnce dedup-guard — one table per CycleAtMostOnceConfig
-- (default table name: 'idempotency_at_most_once'). Same shape as the inbox.
CREATE TABLE idempotency_at_most_once (
    "key"       VARCHAR(512) NOT NULL,
    result      TEXT         NULL,
    create_time BIGINT       NOT NULL,
    PRIMARY KEY ("key")
);
CREATE INDEX idempotency_at_most_once_create_time_index ON idempotency_at_most_once (create_time);

-- Dialect notes
-- * `key` is a reserved word — keep it quoted: "key" (Postgres/SQLite/ANSI), `key` (MySQL).
-- * MySQL: replace "key" with `key`; BOOLEAN is TINYINT(1) (fine); use InnoDB + utf8mb4 —
--   VARCHAR(512) as PRIMARY KEY fits the 3072-byte index limit (512 × 4 = 2048).
-- * Postgres / SQLite: the DDL above runs as-is.
