# spiral/idempotency — agent notes

Idempotency guarantees over Cycle DBAL/ORM: **AtLeastOnce** (lease + fencing-token CAS) and
**ExactlyOnce** (inbox: `INSERT ... ON CONFLICT DO NOTHING` + side-effect in one DB transaction).
Cycle is an *optional* driver — core (`src/` outside `Driver/Cycle`) must carry no `Cycle\*` types.

Transports are optional too: their packages are `require-dev` + `suggest`, and their types stay
confined to the transport adapter dir. HTTP (`src/Http`, `psr/http-message`) and Queue (`src/Queue`,
`spiral/queue`) each bind a `transport:`-flavored `IdempotencyInterceptor` in their dispatcher scope
(`http` / `queue`) under the shared `IdempotencyInterceptorInterface` alias. Queue: `spiral/queue`
types appear ONLY in `src/Queue/RetryableLockException` (adapts `Locked` → the native
`RetryableExceptionInterface` so `RetryPolicyInterceptor` re-enqueues); the key/retry middleware use
only `spiral/interceptors`. We do NOT reimplement retry/backoff — Spiral's engine owns it.

## Testing

Two suites (see `testo.php`), framework is **Testo** (not PHPUnit): `#[Test]`, `#[Covers]`,
`Testo\Assert` / `Testo\Expect`.

- **Unit** (`tests/Unit`) — no database, runs everywhere.
- **Acceptance** (`tests/Acceptance`) — runs the same scenarios against a matrix of SQL drivers
  (SQLite / Postgres / MySQL) via a Testo plugin (`tests/Acceptance/Testo`). The abstract scenarios
  live in `tests/Acceptance/Common`; one empty `#[Group('driver-<x>')]` subclass per driver in
  `tests/Acceptance/Driver/<Driver>` is what Testo discovers.

### Writing acceptance tests — IMPORTANT

Tables are created **once per driver** and are **never cleaned between tests** (unlike Cycle
ActiveRecord, we do NOT wrap each test in a rolled-back transaction — the inbox driver uses
`TransactionMode::Exclusive`, which throws if an outer transaction is already open, and the whole
point of the dedup test is a real top-level COMMIT).

Therefore **every test must use a distinct idempotency key** so its rows never collide with another
test's rows in the shared tables. Use `$this->key()` (a per-call unique key) from `DatabaseTestCase`
and reuse that one value within the test. For helper side-effect tables (e.g. `ledger`), tag rows
with the unique key and count only those (`WHERE note = $key`).

Read the connection from the base class: `$this->db()` / `$this->manager()`. If the target database
is unreachable the plugin marks the test **skipped** (never failed).

### Running

```
composer test:unit                 # no DB
composer test:sqlite               # acceptance on SQLite (in-memory, no service needed)
composer test:pgsql                # acceptance on Postgres  (needs docker-compose service)
composer test:mysql                # acceptance on MySQL
composer test:no-driver            # everything except driver-bound tests

docker compose -f tests/docker-compose.yml up -d     # Postgres :15432, MySQL :13306
```

Driver connection defaults live in `tests/Acceptance/Testo/DatabaseDriver.php` (overridable via
`DB_HOST` / `DB_PORT` / `DB_USER` / `DB_PASSWORD` / `DB_DATABASE`) and match the docker-compose file.

## Cross-dialect gotchas

- MySQL `rowCount()` (PDO default) returns *changed*, not *matched* rows — a no-op UPDATE returns 0.
  This is why the lease `renew()`/`complete()` CAS and the inbox affected-row dedup are dialect-sensitive
  and MUST be covered on MySQL, not only SQLite.
- The upsert `DO NOTHING` affected-row count relies on `cycle/database >= 2.21` (it fixed the MySQL
  row-alias bug that made `DO NOTHING` ambiguous and the Postgres quoted-`EXCLUDED` bug).
