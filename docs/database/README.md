# Database and migrations

[`DATABASE_SPEC.md`](DATABASE_SPEC.md) in this directory is the design source of
truth. The Eloquent models and Laravel migrations are derived from it and must
be reconciled with it. Changing a core business invariant needs architectural
review, not just a migration.

> **Reconciliation note.** `DATABASE_SPEC.md` v1.0 was written against Prisma.
> Its Prisma-specific _directions_ were migrated to Eloquent and Laravel
> migrations in v1.1, as `CLAUDE.md` §16 requires — §6, §8 and §11 of the spec
> now describe the Laravel mechanism. The _schema design_ — tables, columns,
> keys, indexes, constraints, invariants, transaction boundaries and the payment
> workflow — stands unchanged from the reviewed v1.0.
>
> The table below is kept as a reading aid for anyone holding an older copy of
> the spec, or a Prisma-era branch, commit or review comment.

| Older spec wording (Prisma)                  | Current mechanism (Laravel)                                         |
| -------------------------------------------- | ------------------------------------------------------------------- |
| `schema.prisma`                              | `database/migrations/` plus Eloquent models in each module          |
| `prisma migrate dev`                         | `php artisan migrate`                                               |
| `prisma migrate deploy`                      | `php artisan migrate --force`, as a one-off job                     |
| never `prisma db push` in production         | never `migrate:fresh`, `db:wipe` or `migrate:refresh` in production |
| `@db.Timestamptz(6)`                         | `$table->timestampsTz()` / `timestampTz()`                          |
| `BigInt` for VND                             | `bigInteger`, wrapped in `Money`                                    |
| `@default(dbgenerated("gen_random_uuid()"))` | `->default(DB::raw('gen_random_uuid()'))`                           |
| Prisma Client generation                     | nothing — Eloquent needs no codegen                                 |

## What exists today

Phase 01 covers identity, stores and platform infrastructure:

| Domain    | Tables                                                                                              |
| --------- | --------------------------------------------------------------------------------------------------- |
| Identity  | `users`, `auth_sessions`, `roles`, `permissions`, `role_permissions`, `user_roles`, `store_members` |
| Stores    | `stores`, `store_settings`, `store_hours`, `store_closures`                                         |
| Platform  | `audit_logs`, `outbox_events`, `idempotency_records`                                                |
| Framework | `migrations`, `jobs`, `job_batches`, `failed_jobs`, `personal_access_tokens`                        |

`store_members` belongs to the Identity module (`CLAUDE.md` §5) but is created
after `stores`, because it references both.

Catalog, menu, inventory, cart, order, payment and fulfillment tables arrive
with their modules, from `DATABASE_SPEC.md` §4.3–§4.9. They are not
pre-created.

## Conventions

- **UUID** primary keys, defaulted by PostgreSQL `gen_random_uuid()`; the models
  generate **UUIDv7**, so keys are time-ordered and inserts keep index locality.
- **snake_case** tables and columns.
- **`timestamptz`** everywhere, stored in UTC. Schedules are evaluated in the
  store's own timezone (`stores.timezone`, default `Asia/Ho_Chi_Minh`).
- **Money is `bigInteger`** whole VND. Never float, never decimal-as-float.
- **No cascade deletes of transactional data.** `store_members` and `user_roles`
  use `restrictOnDelete`; `audit_logs` uses `nullOnDelete`, so history outlives
  the store or account it refers to. `cascadeOnDelete` appears only where the
  child is meaningless without its parent (a store's hours, a user's sessions).

## Running migrations

Development:

```bash
docker compose --env-file .env -f infra/compose.yaml -f infra/compose.dev.yaml exec api php artisan migrate
```

Deployed environments — an explicit one-off job, so two API replicas can never
migrate concurrently:

```bash
docker compose --env-file .env -f infra/compose.yaml -f infra/compose.prod.yaml run --rm migrate
```

Rules:

- **Never `migrate:fresh`, `db:wipe` or `migrate:refresh`** against anything
  shared. They drop data.
- **Never migrate on API startup.** The container entrypoint caches and
  validates configuration but does not migrate; `compose.yaml` defines a
  separate `migrate` service for exactly this reason.
- The migrate job connects as `MIGRATION_DB_USERNAME` (DDL rights). The api,
  worker and scheduler connect as `DB_USERNAME`, which has none — so a SQL
  injection in a request handler cannot drop a table.
  `infra/scripts/postgres-init/` creates both roles on first boot.
- Review every migration before committing. Read the `drop` and `change` lines
  twice.

## Raw SQL

Laravel's schema builder cannot express CHECK constraints or partial indexes, so
`2026_11_09_000500_add_check_constraints_and_partial_indexes.php` uses raw SQL —
the justified use the conventions allow (`CLAUDE.md` §7).

A constraint in the database is the only rule that also holds for a seeder, a
data fix, or a future service talking to the same tables. What it enforces:

- enum columns accept only their documented values;
- `store_hours.day_of_week` is 1–7 (ISO-8601);
- `store_closures.end_at > start_at`;
- `store_settings.order_lead_minutes >= 0`;
- `auth_sessions.expires_at > created_at`;
- `outbox_events.published_at` is set exactly when status is `PUBLISHED`;
- a `COMPLETED` idempotency record carries the response it replays.

Partial indexes added there:

```sql
CREATE INDEX idx_outbox_pending ON outbox_events (created_at)
  WHERE status = 'PENDING';

CREATE INDEX idx_store_members_active ON store_members (user_id, store_id)
  WHERE status = 'ACTIVE';

CREATE UNIQUE INDEX idx_users_email_lower ON users (lower(email))
  WHERE email IS NOT NULL;
```

The last one matters for security: two accounts differing only in capitalisation
would be an account-takeover risk.

Still to add with their tables: one active default bank account per store, from
`DATABASE_SPEC.md` §6.

## Backward compatibility

A deploy is: migrate, then roll out code. So **every migration must be
compatible with the currently running code** — that is what makes rollback
possible. CI proves the set is reversible by rolling back and re-applying it.
Breaking changes take two releases:

1. Add the new column or table, write to both, backfill, deploy.
2. Stop reading the old one, then drop it in a later release.

## Backups

A Docker volume is not a backup.

```bash
./infra/scripts/db-backup.sh
```

Copy the dump off-host and **test the restore** on a schedule — an untested
backup is not a backup. Prefer managed PostgreSQL in production; if
self-hosting, document the restore procedure and patch cadence.

## Seeding

```bash
docker compose --env-file .env -f infra/compose.yaml -f infra/compose.dev.yaml exec api php artisan db:seed
```

`RolePermissionSeeder` is reference data (17 permissions, 5 roles) and runs in
every environment including production — without it nobody can be granted
anything. It is idempotent, and it replaces the whole grant set per role, so
deleting a permission from the file actually revokes it.

`DemoStoreSeeder` creates a store, its hours and four staff accounts with a
known password. It refuses to run when `APP_ENV=production`, and
`DatabaseSeeder` gates it as well: a demo account with a known password in a
production database is a backdoor.

## Testing against the database

Integration and feature tests run against **real PostgreSQL**, never SQLite
(`CLAUDE.md` §12). Row locking, partial indexes, CHECK constraints and
`timestamptz` behaviour are load-bearing, and SQLite silently behaves
differently. `infra/scripts/postgres-init/` creates `smart_commerce_test` on
first boot, and `phpunit.xml` points at it.

## Before each release

- `EXPLAIN` the hot paths: order list by store and date, today's menu, payment
  lookup by order, and the store-membership authorization query.
- Watch connection count, slow queries, lock waits, disk growth, outbox lag.
- Confirm migration state matches across environments: `php artisan migrate:status`.
