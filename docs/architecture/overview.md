# Architecture overview

Authoritative rules live in `CLAUDE.md` §3–§6 and §16. This page explains how
the code on disk implements them, so a new contributor can find their way
around.

## Shape

A **modular monolith**, not microservices. One Laravel codebase, three
processes, one PostgreSQL database, organised internally by bounded business
module.

```text
                         ┌──────────────┐
      public traffic ───►│   gateway    │  Nginx, only service on 80/443
                         └──┬────┬──────┘
             ┌──────────────┘    └──────────────┐
             │ HTTP proxy                       │ FastCGI
             ▼                                  ▼
  ┌────────────────────────┐            ┌───────────────┐
  │ customer-web           │            │      api      │  Laravel on PHP-FPM
  │ admin-web   (Next.js)  │            └───┬───────┬───┘
  └────────────────────────┘                │       │
                                            │       │
         ┌──────────────────────────────────┘       │
         ▼                                          ▼
  ┌─────────────┐   ┌─────────────┐          ┌─────────────┐
  │  postgres   │   │   worker    │          │    redis    │
  │ source of   │◄──│  Horizon    │─────────►│ cache+queue │
  │   truth     │   └─────────────┘          └─────────────┘
  └─────────────┘   ┌─────────────┐
         ▲          │  scheduler  │  exactly one replica
         └──────────│ schedule:work│
                    └─────────────┘
```

`api`, `worker` and `scheduler` are the **same image with different
entrypoints** (`php-fpm`, `php artisan horizon`, `php artisan schedule:work`).
Queue work therefore cannot starve request handling, and each scales
independently.

### Why the gateway speaks FastCGI

PHP-FPM is not an HTTP server, so the gateway talks FastCGI to `api:9000`. The
include at `infra/nginx/conf.d/fastcgi-api.inc` sets a **fixed**
`SCRIPT_FILENAME` of `/var/www/html/public/index.php` and deliberately has no
`try_files`:

- this API serves no static files — the Next.js apps serve the UI — so every
  request is the front controller;
- the gateway holds no copy of the application code and needs none;
- handing PHP a client-derived script path is how FastCGI deployments get
  remote code execution.

## Layers inside a module

```text
apps/api/app/Modules/<Module>/
├── Domain/{Entities,Enums,Events,Contracts}/   business rules, no framework
├── Application/{Actions,DTOs,Services}/        use cases, transaction boundaries
├── Infrastructure/{Persistence,Repositories}/  Eloquent, queues, gateways
├── Presentation/{Controllers,Requests,Resources}/
└── <Module>ServiceProvider.php
```

Dependencies point inward. `Domain/` knows nothing about Laravel, Eloquent or
HTTP — it is plain PHP holding business rules, so those rules are testable
without booting the framework or touching a database.

**Laravel is an adapter, not the domain model** (`CLAUDE.md` §16). Eloquent
models live in `Infrastructure/Persistence/Model/` and map rows; they are not
where business decisions belong.

Two rules are enforced by tests rather than review, in
`tests/Unit/ArchitectureTest.php`:

- `Domain/` may not use `Illuminate\Database\Eloquent\Model`,
  `Illuminate\Http\Request` or `Illuminate\Support\Facades`.
- Every class declares `strict_types=1`, and no `dd`/`dump`/`die` survives.

## Cross-module communication

A module never touches another module's tables or repositories. The permitted
routes are:

1. the other module's exported **application service** —
   `Identity` exposes `ChecksStorePermissions`, `Store` exposes `StoreContext`;
2. a published **contract** in `packages/api-contracts` (shared with the web apps);
3. a **domain event** through the outbox.

Module providers are listed explicitly in `bootstrap/providers.php`, so the set
of active modules is one readable list rather than the result of discovery.

## Store-scoped authorization

The single most damaging bug this system could ship is one store acting on
another's data, so the check is deliberately two independent conditions:

```php
Route::post('/admin/payments/{payment}/confirm', ...)
    ->middleware(['auth:sanctum', 'store.resolve', 'store.can:payment.confirm']);
```

- `store.resolve` (`ResolveStoreContext`) decides **which** store, from a route
  parameter, a configured host mapping, or the single-store default — **never**
  from the query string, which is attacker-controlled.
- `store.can` (`EnsureStorePermission`) checks the permission **in that store**:
  either a `GLOBAL` role (SUPER_ADMIN), or a `STORE` role held through an
  **active** membership of that specific store.

Denial is identical whether the user lacks the permission or is not a member at
all, so responses cannot be used to probe which stores exist or who staffs them.
An inactive store reports 404, not "disabled" — its existence is not public.

## Infrastructure and shared code

| Location                                                       | Responsibility                         |
| -------------------------------------------------------------- | -------------------------------------- |
| `app/Shared/Domain/ValueObject/Money.php`                      | exact whole-đồng arithmetic            |
| `app/Shared/Presentation/Http/ErrorCode.php`                   | stable machine-readable error codes    |
| `app/Shared/Presentation/Http/Middleware/AssignRequestId.php`  | correlation id, validated before reuse |
| `app/Shared/Infrastructure/Exception/ApiExceptionRenderer.php` | the one error envelope                 |
| `app/Shared/Infrastructure/Config/EnvironmentValidator.php`    | boot-time configuration check          |
| `app/Shared/Infrastructure/Persistence/Model/`                 | audit log, outbox, idempotency records |

## Request lifecycle

1. Nginx mints or forwards `X-Request-Id` and sheds obvious floods.
2. `AssignRequestId` validates the inbound id (rejecting anything that could
   poison a log file or a header), attaches it to the request, the response and
   the log context.
3. The named `api` rate limiter applies the per-user or per-IP limit.
4. Form Requests validate input; API Resources shape output.
5. The controller delegates to an application Action. No business logic there.
6. `ApiExceptionRenderer` maps any failure to the documented envelope, logging
   5xx with a stack and 4xx briefly, and never leaking internals.

## Consistency and events

PostgreSQL is the source of truth. Redis is never authoritative for orders,
payments or stock.

Critical flows write business state **and** an `outbox_events` row in one
`DB::transaction()`. A worker then publishes pending rows. Laravel's
`dispatchAfterCommit` is not a substitute: it fires after commit but keeps
nothing durable, so a crash between commit and dispatch loses the event
silently. Consumers must be idempotent, because at-least-once delivery means
duplicates are normal.

## Concurrency rules that matter

- **Overselling** is prevented in PostgreSQL — `lockForUpdate()` or a
  conditional `UPDATE` on `stock_items` inside the order transaction, not a
  Redis lock.
- **Checkout is idempotent** — `idempotency_records` stores the result per
  `(scope, key)` under a unique index, so a retry collides instead of creating a
  second order. A key reused with a different `request_hash` is rejected.
- **Order state and payment state are separate machines.** `COMPLETED` never
  implies `PAID`.
- **One scheduler per deployment.** Two would expire the same reservation twice.

## Core versus extension

`CLAUDE.md` §16 separates generic commerce from food specifics:

- **Core**: `Product`, `Variant`, `Price`, `Inventory`, `Cart`, `Order`,
  `Payment`. Selling retail goods must be possible with these alone.
- **Extension**: `Menu`, `Modifiers`, `Kitchen`, daily portions.

Appointment-based services are out of scope and would need a future `Booking`
module rather than being bent into `Menu`.

## What Phase 01 deliberately leaves out

Only `Identity` and `Store` exist, because the milestone needs a Sanctum
baseline and the store-scoping convention. The other thirteen modules from
`CLAUDE.md` §5 are absent rather than present as empty scaffolds (§4), as is any
AI infrastructure (§11) and any payment gateway adapter (§6).
