# Smart Commerce Platform

Mobile-first, configurable commerce platform. Generic retail commerce at the
core; the food-specific parts (menus, modifiers, kitchen, daily portions) are
extensions on top, so a store selling packaged goods needs neither a menu nor a
kitchen ticket.

`CLAUDE.md` is the architectural source of truth.
[`docs/database/DATABASE_SPEC.md`](docs/database/DATABASE_SPEC.md) is the data-model
source of truth. Read both before changing anything structural.

**Current milestone: Phase 01 — Foundation.** Workspace, Laravel API bootstrap,
Docker stack, PostgreSQL and Redis wiring, the first migrations, and CI. No
business features and no AI, by design.

---

## Stack

| Concern        | Choice                                            | Pinned                             |
| -------------- | ------------------------------------------------- | ---------------------------------- |
| Backend        | Laravel, modular monolith                         | PHP 8.4, Laravel 13.40             |
| Database       | PostgreSQL + Eloquent                             | PostgreSQL 16.6                    |
| Cache / queues | Redis + Laravel Queues + Horizon                  | Redis 7.4, Horizon 5.50            |
| Auth           | Sanctum, Policies/Gates, store-scoped RBAC        | Sanctum 4.3                        |
| Web            | Next.js App Router, React, Tailwind + shadcn/ui   | Next 15.1, React 19                |
| Gateway        | Nginx, FastCGI to PHP-FPM                         | Nginx 1.27                         |
| PHP quality    | Pint, PHPStan/Larastan level 8, Pest              | Pint 1.32, Larastan 3.14, Pest 5.3 |
| Repository     | Composer for PHP, pnpm + Turborepo for JavaScript | pnpm 10.34                         |

This set is verified: Pint, PHPStan level 8 and 39 Pest tests pass, the
migrations apply and roll back against PostgreSQL 16, and the API answers
through the Nginx gateway. Commit `composer.lock` and `pnpm-lock.yaml`.

## Layout

```text
apps/
  api/            Laravel API, queue worker and scheduler
    app/Modules/  one directory per bounded module, four layers each
    app/Shared/   cross-cutting: Money, ErrorCode, middleware, outbox
  customer-web/   Next.js storefront
  admin-web/      Next.js operations dashboard
packages/
  api-contracts/  error codes, headers, route table, shared enums and money type
  ui/             Tailwind preset and shared UI primitives
  config/         shared ESLint, TypeScript and Prettier configs
infra/
  compose.yaml    base stack; compose.dev.yaml / compose.prod.yaml override it
  nginx/          gateway: proxies the web apps, FastCGI to the API
  monitoring/     Prometheus and Grafana (optional, profile "monitoring")
  scripts/        bootstrap-dev.sh, db-backup.sh, postgres-init/
docs/             architecture, api, database, specifications, development
```

PHP dependencies are managed by Composer and JavaScript by pnpm. Turborepo
orchestrates the JavaScript workspaces only — it never drives PHP.

## Prerequisites

- Docker with Compose v2 (`docker compose version`) — the only hard requirement
- Node.js 22.11+ and pnpm 10+, to work on the web apps outside Docker
- PHP 8.4 and Composer, only if you want to run artisan on the host

### The checkout must sit on a filesystem with symlinks

pnpm links workspace packages with symlinks, so the repository has to live on
**NTFS, APFS, ext4 or btrfs**. On **exFAT or FAT32 `pnpm install` fails** with
`EISDIR`, and no pnpm setting avoids it. Composer and the Docker builds are
unaffected — only the JavaScript workspaces need this. Check with:

```bash
df -T .
```

## Setup

```bash
cp .env.example .env
```

Generate an application key and put it in `.env` as `APP_KEY` — the API refuses
to boot without one:

```bash
docker compose --env-file .env -f infra/compose.yaml -f infra/compose.dev.yaml run --rm --no-deps api php artisan key:generate --show
```

Bring up the stack:

```bash
docker compose --env-file .env -f infra/compose.yaml -f infra/compose.dev.yaml up -d --build
```

Apply migrations as an explicit one-off job — never part of API startup:

```bash
docker compose --env-file .env -f infra/compose.yaml -f infra/compose.dev.yaml run --rm migrate
```

Seed roles, permissions and a demo store:

```bash
docker compose --env-file .env -f infra/compose.yaml -f infra/compose.dev.yaml exec api php artisan db:seed
```

| Service         | URL                                 |
| --------------- | ----------------------------------- |
| Storefront      | http://localhost:8080               |
| Admin dashboard | http://admin.localhost:8080         |
| API             | http://localhost:8080/api/v1        |
| Liveness        | http://localhost:8080/health/live   |
| Readiness       | http://localhost:8080/health/ready  |
| Horizon         | http://admin.localhost:8080/horizon |

The gateway is the only published port. PostgreSQL and Redis bind to
`127.0.0.1` in development and to nothing at all in production.

Optional monitoring:

```bash
docker compose --env-file .env -f infra/compose.yaml -f infra/compose.dev.yaml --profile monitoring up -d
```

## Commands

Backend (inside the api container; `pnpm api:*` wraps these):

| Command                  | Does                                     |
| ------------------------ | ---------------------------------------- |
| `pnpm api:pint`          | Laravel Pint, fix mode                   |
| `pnpm api:stan`          | PHPStan / Larastan level 8               |
| `pnpm api:test`          | Pest                                     |
| `pnpm api:check`         | Pint check, PHPStan and Pest in sequence |
| `pnpm api:migrate`       | Run the one-off migration job            |
| `pnpm api:artisan <cmd>` | Any artisan command                      |
| `pnpm api:openapi`       | Regenerate `docs/api/openapi.json`       |

Frontend:

| Command                        | Does                            |
| ------------------------------ | ------------------------------- |
| `pnpm dev`                     | Both web apps in watch mode     |
| `pnpm build`                   | Build the web apps and packages |
| `pnpm lint` / `pnpm typecheck` | ESLint / TypeScript             |
| `pnpm format:check`            | Prettier (CI runs this)         |

Infrastructure:

| Command                        | Does                       |
| ------------------------------ | -------------------------- |
| `pnpm infra:up` / `infra:down` | Start / stop the stack     |
| `pnpm infra:logs`              | Follow logs                |
| `pnpm infra:config`            | Validate the Compose files |

## Conventions worth knowing before the first commit

- **Money is exact.** VND is whole đồng in `BIGINT` and PHP `int`, wrapped in
  `App\Shared\Domain\ValueObject\Money`, and serialised to JSON as a decimal
  string. No floats anywhere near a price.
- **Timestamps are UTC** (`timestamptz`); opening hours, menu schedules and
  daily quotas are evaluated in the store's own timezone, default
  `Asia/Ho_Chi_Minh`.
- **Layers.** Each module has `Domain/`, `Application/`, `Infrastructure/`,
  `Presentation/`. `tests/Unit/ArchitectureTest.php` fails the build if
  `Domain/` touches Eloquent, facades or HTTP.
- **Store scope is two checks, not one.** Every admin operation needs the role
  permission _and_ an active membership of that store. The convention is
  `->middleware(['auth:sanctum', 'store.resolve', 'store.can:<permission>'])`.
  Store context never comes from a query parameter.
- **Payments in MVP are `CASH` and `BANK_TRANSFER` only.** A generated VietQR is
  not a payment confirmation; authorized staff confirm the actual bank credit.
- **PostgreSQL is the source of truth.** Redis is cache and queue transport.
- **Migrations are reviewed and run as a deploy job.** Never `migrate:fresh`,
  `db:wipe` or `migrate:refresh` against anything shared.
- Every change ships with tests and, where relevant, a migration and an
  OpenAPI update.

## Documentation

- [Architecture overview](docs/architecture/overview.md)
- [API conventions](docs/api/README.md) — and the generated
  [OpenAPI document](docs/api/openapi.json)
- [Database and migrations](docs/database/README.md)
- [Data model specification](docs/database/DATABASE_SPEC.md)
- [Local development](docs/development.md)
- [Open decisions](docs/specifications/open-decisions.md)
