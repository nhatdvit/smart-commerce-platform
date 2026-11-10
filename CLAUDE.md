# CLAUDE.md — Smart Commerce Platform

> Project instructions for Claude Code. This file is the architectural source of truth for implementation decisions. Read it before planning, coding, reviewing, or deploying. If a requested change conflicts with this document, explain the trade-off and ask for approval before changing architecture.

## 1. Product vision and scope

Build a mobile-first, multi-store, configurable commerce and ordering platform. The first vertical is Vietnamese breakfast and beverages (bánh mì, xôi, phở, bún, coffee, combos), but the core must support general physical products, packaged goods and retail without requiring a rewrite. Food-specific menus, modifiers, kitchen workflows and portion inventory belong in optional domain modules; appointment-based services are future scope and require their own booking model. Customers browse a scheduled menu, customize items, add to cart, order, pay, and track orders. Staff manage menus, orders, kitchen, inventory, and reports. **AI is not part of MVP**; preserve clean business APIs and structured product data for future AI chat, recommendations, and cart operations.

### Delivery stages
1. **MVP:** Customer web, admin web, store setup, catalog, scheduled menus, cart, checkout, orders, MVP payments (cash on pickup/delivery and bank transfer with dynamically generated VietQR), basic admin and notifications.
2. **Operations:** Kitchen display, realtime, reservations/preorders, inventory limits, promotions, reporting, RBAC refinements.
3. **AI (later):** LLM tools for live menus, availability, product search, recommendations, cart assistance, and order status.
4. **Scale:** More stores, loyalty, POS integration, mobile apps, forecasting.

Do not implement speculative AI infrastructure in Phase 1.

## 2. Approved technology stack

| Concern | Decision |
|---|---|
| Customer web | Next.js + React + TypeScript |
| Admin web | Next.js + React + TypeScript |
| UI | Tailwind CSS + shadcn/ui; mobile-first, accessible |
| Backend | PHP 8.4+ and a supported stable Laravel release; pin tested compatible versions |
| Database | PostgreSQL |
| ORM / migrations | Laravel Eloquent + Laravel migrations / seeders / factories |
| Cache / background jobs | Redis + Laravel Queues + Horizon |
| Realtime | Laravel Reverb + Broadcasting (when required) |
| API contract | RESTful `/api/v1` + OpenAPI/Swagger |
| Validation | Laravel Form Requests, validation rules, API Resources |
| Auth / authorization | Laravel Sanctum, Policies/Gates, RBAC + store-scoped authorization |
| Storage | S3-compatible; MinIO for local development when needed |
| Infra | Docker, Docker Compose, Nginx reverse proxy |
| CI/CD | GitHub Actions |
| Observability | Structured logs, OpenTelemetry, Prometheus/Grafana, Sentry as configured |
| Future AI | Laravel application services and provider adapters; Python/FastAPI only if justified |
| Repository | Git monorepo; pnpm workspaces + Turborepo for Next.js frontends/packages, Composer for Laravel backend |

Pin and document compatible runtime/library versions when scaffolding. Do not assume the latest major release is compatible with all dependencies.

## 3. Architecture rules (non-negotiable)

- **Modular Monolith first**, not microservices. Organize by bounded business module.
- Apply **Clean Architecture**, SOLID, dependency inversion, and explicit module boundaries.
- Layers per module: `domain/`, `application/`, `infrastructure/`, `presentation/`.
- Domain holds business rules and must not depend on Laravel facades, Eloquent models, HTTP, or external providers. Keep framework-specific code in adapters and infrastructure.
- Application implements use cases and coordinates transactions through interfaces.
- Infrastructure implements persistence, queues, gateways, and integrations.
- Presentation contains controllers, request validation, response mapping, and transport adapters.
- Controllers must be thin. Use Form Requests for input validation, API Resources for output, and application Actions/Use Cases for orchestration. No complex business logic or Eloquent query chains in controllers.
- Do not directly access another module's private repository/tables. Use exported application services, contracts, or domain events.
- Avoid circular module dependencies, hidden container resolution and excessive service-locator/facade use in domain logic.
- PostgreSQL is the source of truth. Redis is not the authoritative source for orders, payments, or stock.
- Expose reusable use cases independent of HTTP, so future AI can call the same validated operations.
- Use consistent error codes, request IDs, structured logging, and meaningful API status codes.
- All production changes require tests and migrations where applicable.

## 4. Repository layout

```text
smart-commerce-platform/
├── apps/
│   ├── customer-web/              # Next.js customer storefront
│   ├── admin-web/                 # Next.js administration
│   └── api/                       # Laravel API, queue and scheduler
│       ├── app/
│       │   ├── Modules/
│       │   │   ├── Identity/
│       │   │   ├── Store/
│       │   │   ├── Catalog/
│       │   │   ├── Menu/
│       │   │   ├── Inventory/
│       │   │   ├── Cart/
│       │   │   ├── Order/
│       │   │   ├── Payment/
│       │   │   └── Fulfillment/
│       │   ├── Shared/
│       │   └── Providers/
│       ├── bootstrap/
│       ├── config/
│       ├── database/{migrations,factories,seeders}/
│       ├── routes/api.php
│       ├── tests/{Unit,Feature}/
│       ├── composer.json
│       └── Dockerfile
├── packages/{api-contracts,ui,config}/
├── infra/{nginx,monitoring,scripts}/
├── docs/{architecture,database,api,specifications}/
├── .github/workflows/
├── pnpm-workspace.yaml
├── turbo.json
└── CLAUDE.md
```

Example Laravel module (PSR-4 autoloading and service-provider registration must be configured explicitly):

```text
apps/api/app/Modules/Order/
├── Domain/{Entities,Enums,Events,Contracts}/
├── Application/{Actions,DTOs,Services}/
├── Infrastructure/{Persistence,Repositories}/
├── Presentation/{Controllers,Requests,Resources}/
└── OrderServiceProvider.php
```

Use Composer for PHP dependencies, `composer.lock` for reproducible builds and pnpm for JavaScript workspaces. Do not attempt to manage PHP with Turborepo or install Laravel packages through pnpm. Follow the Laravel version's actual application bootstrap/provider conventions; do not assume old `app/Http/Kernel.php` exists. Do not create all modules as empty scaffolds unless required for the milestone.

## 5. Backend modules and ownership

| Module | Responsibilities |
|---|---|
| Auth | Login/logout, session, refresh rotation, credentials |
| Identity | Users, roles, permissions, store membership |
| Store | Stores, hours, holidays, settings, store timezone |
| Catalog | Categories, products, variants, modifiers, attributes |
| Menu | Menus, schedules, exceptions, item availability and store pricing |
| Inventory | Daily portions, stock movements, reservations, release/commit |
| Cart | Guest/authenticated carts, items, selected modifiers |
| Pricing | Server-authoritative prices, promotions, fees, totals |
| Order | Checkout, order snapshot, lifecycle, cancellation, audit history |
| Payment | MVP: cash collection + bank transfer with dynamic VietQR and staff verification; future: reconciliation adapters/webhooks/refunds |
| Fulfillment | Pickup, delivery, scheduled collection and handoff |
| Kitchen | Kitchen queue, preparation and ready statuses |
| Customer | Customer profiles, addresses, order history |
| Notification | In-app, realtime, email/SMS provider adapters |
| Reporting | Sales, bestsellers, operational summaries |
| Audit | Security- and business-sensitive action history |

Infrastructure modules: Database, Cache, Queue, Storage, Health, Observability.

## 6. Core business invariants

### Flexible catalog and menu
- Model `Category → Product → ProductVariant` and `ModifierGroup → ModifierOption` with explicit join tables.
- **Products are master data; menus decide when/where/how products are sold.**
- Support per-store pricing, recurring weekday schedules, breakfast time windows, date overrides/holidays, sold-out status, daily quotas, and preorder cutoffs.
- Timezone is configurable per store; default `Asia/Ho_Chi_Minh`. Store timestamps in UTC (`TIMESTAMPTZ`), evaluate schedule in store-local time.
- Server revalidates current price, modifiers, menu schedule, availability, and pickup/delivery rules at checkout.

### Cart, order, stock
- Allow guest checkout with appropriately scoped and expiring order access; do not expose orders by guessable ID alone.
- Checkout must be **idempotent**; require an idempotency key for order creation and persist its result.
- Prevent overselling via PostgreSQL transaction + row locking or conditional stock updates; do not rely solely on Redis locks/cache.
- Use stock reservations with expiration and release for pending payments where applicable.
- Order lines store immutable snapshots of name, variant, selected modifiers, unit price, quantity, discounts, fees/taxes, and totals.
- Monetary arithmetic must be exact; for VND use `BIGINT` integer đồng or an agreed `DECIMAL`, never floating-point.
- Order state and payment state are separate state machines.

### Order states
Default prepaid flow: `PENDING_PAYMENT → PLACED → CONFIRMED → PREPARING → READY → COMPLETED`.
Allow validated transitions to `CANCELLED` / `EXPIRED` under explicit rules. Cash/pay-on-pickup may progress before `PAID`. Reject invalid transitions and record status history.

### MVP payment methods — approved decision (2026-11-09)
- **Only `CASH` and `BANK_TRANSFER` are in MVP scope.** Do not implement VNPay, MoMo, card processing, payment gateways, or automatic bank reconciliation without a separate approved requirement.
- `CASH`: create an order with payment `PENDING`, allow fulfillment according to store confirmation policy, and let an authorized cashier/manager mark `PAID` **only after collecting cash**. Completing an order must not automatically mark payment as paid.
- `BANK_TRANSFER`: after server-side price/availability validation and order creation, generate a **dynamic VietQR** using the store's configured bank identifier, receiving account, exact VND amount, and unique transfer reference derived from the order code. Display QR plus copyable amount, bank/account details, and transfer reference. Do not expose bank-account management to customers.
- QR generation is **not payment confirmation**. MVP uses **manual verification by authorized staff** after checking the actual bank credit; never let the customer self-confirm `PAID`, and never treat a screenshot, QR display, or "I have transferred" click as proof of funds.
- Bank account configuration is store-scoped; validate account/bank details, restrict changes to authorized roles, and audit all changes. Support enabling/disabling the two methods per store, while ensuring at least one usable method is enabled.
- Manual confirmation records `confirmed_by`, `confirmed_at`, amount, optional bank transaction reference/evidence metadata, and an audit event; make confirmation idempotent and transactional. If the recorded amount differs from the order total, require explicit exception handling rather than auto-marking `PAID`.
- Generate unique transfer references with bank-compatible character/length rules; guard against collisions, duplicate transfers, missing/wrong references, under/overpayment, cancellations, and late transfers. Keep a manual reconciliation queue for exceptions.
- For pending bank transfers, apply configurable expiry/stock reservation rules. A transfer arriving after expiry/cancellation requires manual review/refund policy, not automatic reopening of an expired order.
- Keep a provider interface/adapter boundary for future bank transaction notifications and signed webhook reconciliation; **do not claim automated payment detection is supported by VietQR generation alone**.
- Prefer a standards-compliant VietQR/EMVCo payload or a verified QR-generation integration; ensure amount and reference are URL/payload encoded correctly and QR scans reliably on Vietnamese banking apps. Avoid reliance on an unverified third-party endpoint.

### Payment states
`PENDING`, `PROCESSING`, `PAID`, `FAILED`, `PARTIALLY_REFUNDED`, `REFUNDED` (extend only with documented transitions).
- Never trust the browser redirect as proof of payment.
- Verify webhook signature/credentials, provider transaction identity, amount, currency, and target order.
- Process duplicate/out-of-order webhooks idempotently.
- Reconcile late payments after expiration; refund or manual-review rather than silently reopening expired orders.
- Do not store card credentials or secrets in logs/database.

### Domain events
- Persist business state and outbox events in the same PostgreSQL transaction for critical flows.
- Worker dispatches outbox records to Laravel Queue jobs/realtime/notifications with retries, idempotent consumers, dead-letter handling and monitoring.
- WebSocket events are hints; clients must recover state via REST after reconnect.

## 7. Database design conventions

Logical tables (expand into Eloquent models and reviewed Laravel migrations as work proceeds):

```text
users, roles, permissions, user_roles, store_memberships
stores, store_settings, store_hours
categories, products, product_variants
modifier_groups, modifier_options, product_modifier_groups
menus, menu_items, menu_schedules, menu_exceptions
stock_items, stock_movements, stock_reservations
carts, cart_items, cart_item_modifiers
orders, order_items, order_item_modifiers, order_status_history
payment_methods, store_bank_accounts, payments, payment_transactions, payment_events, refunds (future as needed)
customer_profiles, customer_addresses
promotions, coupons, coupon_redemptions
notifications, audit_logs, outbox_events
# Future only: ai_conversations, ai_messages, ai_tool_executions
```

- Use UUID/UUIDv7 IDs consistently, explicit foreign keys, unique constraints, and indexes.
- Store ownership is enforced by `store_id` and authorization, not by client-supplied store IDs alone.
- Index common store/date/status lookup patterns; verify query plans for hot paths.
- Add `created_at` / `updated_at`; preserve order/payment/audit records rather than hard-delete.
- Use reviewed Laravel migrations (`php artisan migrate --force` in an explicit deployment job). Never use `migrate:fresh`, `db:wipe`, `migrate:refresh` or destructive schema operations in production. Use Eloquent models and query builder; raw SQL only when justified for atomic operations, indexes or performance.
- Seed only demo data in non-production unless expressly approved.
- Backups must be restorable and stored off-host in production.

## 8. API and security contract

- Base path: `/api/v1`; maintain OpenAPI docs and generate clients/contracts where practical.
- HTTP: `200/201/204` success; `400/401/403/404/409/422/429` as appropriate.
- Validate/whitelist DTO fields, cap payload size, use cursor pagination for large lists.
- Apply rate limits, secure headers, CORS allowlist, CSRF protection for cookie-auth flows.
- Admin roles: `SUPER_ADMIN`, `STORE_OWNER`, `STORE_MANAGER`, `CASHIER`, `KITCHEN_STAFF`; customer access separately scoped.
- Every admin operation must check both role permission **and store membership/scope**.
- Prefer secure `HttpOnly`, `Secure`, appropriate `SameSite` cookies for web sessions. If using JWT, short-lived access tokens, rotating refresh tokens, revocation, and hashed persisted refresh identifiers.
- Never return internal stack traces or secrets; redact personal data and payment details in logs.
- Audit sensitive actions and enforce least privilege.
- Follow OWASP ASVS principles, dependency scanning, and secure-by-default configuration.

Initial endpoints (expand with versioned OpenAPI contracts):

```text
GET    /api/v1/stores/{id}
GET    /api/v1/menus/today
GET    /api/v1/products
GET    /api/v1/products/{id}
POST   /api/v1/carts
POST   /api/v1/carts/{id}/items
POST   /api/v1/orders
GET    /api/v1/orders/{id}
GET    /api/v1/payment-methods
GET    /api/v1/orders/{id}/payment
GET    /api/v1/admin/payments
POST   /api/v1/admin/payments/{id}/confirm
PUT    /api/v1/admin/stores/{id}/bank-account
# Future only: POST /api/v1/payments/webhooks/{provider}
GET    /api/v1/admin/orders
PUT    /api/v1/admin/menus/{id}
```

Determine the store context explicitly (e.g. domain, route, or verified membership), not through untrusted query parameters.

## 9. Docker / infrastructure standards

### Runtime services
- `gateway`: Nginx, only public entrypoint on ports 80/443.
- `customer-web`, `admin-web`: Next.js, internal ports only.
- `api`: Laravel PHP-FPM process behind Nginx; expose `/health/live` and `/health/ready`.
- `worker`: Laravel `queue:work` or Horizon process from the same PHP image, separate entrypoint; run scheduler as a separate service or supervised process.
- `postgres`: private persistent database.
- `redis`: private cache and queue; persistence as needed for job durability.
- `minio`: optional local S3-compatible storage.
- `prometheus`, `grafana`: optional monitored deployment, secured access.

### Network and secrets
- Frontend network connects gateway/web/api; backend private network connects api/worker/database/redis/storage.
- Do not publish PostgreSQL or Redis ports publicly. Restrict outbound connectivity intentionally; workers that send external notifications need controlled egress.
- `.env` is local only; commit `.env.example`, never real credentials. Use deployment secrets for production.
- Docker volumes for stateful services; healthchecks and restart policies for services.

### Build and deploy
- Multi-stage Dockerfiles: reproducible `composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader` for PHP production and `pnpm install --frozen-lockfile` for Next.js workspaces; non-root runtime, `.dockerignore`, small images.
- Build JS workspace dependencies correctly; optimize Laravel autoload/config/routes only when environment configuration supports it. No Prisma client generation.
- Pin compatible PHP, Composer, Node LTS and base image versions/digests; scan images and both Composer/npm dependencies.
- Run migrations as an explicit one-off deploy job, **not** concurrently on API startup.
- API/worker support graceful shutdown (`SIGTERM`), liveness/readiness endpoints, bounded retries/timeouts. Use `php artisan queue:restart` or Horizon termination as appropriate during deployment.
- Keep `compose.yaml`, `compose.dev.yaml`, `compose.prod.yaml` separated with documented overrides.
- Do not use `docker compose down` as a routine production deployment step.
- Stage deploys before production; preserve rollback compatibility with DB migrations.

### CI/CD
PR: Laravel Pint → PHPStan/Larastan → Pest/PHPUnit unit/feature/integration tests → frontend ESLint/TypeScript checks → Composer/npm security checks → Docker build.
Main/release: push versioned image → staging deploy → E2E/smoke → approval → production migration → deploy → health checks/monitoring.
Back up PostgreSQL automatically and **test restores**; set retention for logs, metrics and backups.

## 10. Reliability and observability

- Correlation/request IDs across API, jobs and payment webhooks.
- JSON structured logs; no secrets, credentials, raw payment payloads, or unnecessary PII.
- Metrics: HTTP p95/error rate, DB pool, Redis, queue depth/age, order failures, payment mismatch, stock contention.
- Tracing via OpenTelemetry; error tracking via Sentry if configured.
- Use retry with exponential backoff and dead-letter handling for transient external failures.
- Initial performance goals (to validate under load): p95 read API < 300ms, p95 order-write API < 800ms, excluding external provider latency.
- Design for recoverability, not just happy-path success.

## 11. Future AI readiness (not MVP implementation)

- Product attributes should support structured ingredients, spice level, tags, descriptions, price and availability.
- Future tools: `get_today_menu`, `search_products`, `get_product_details`, `check_availability`, `recommend_combo`, `add_to_cart`, `get_cart`, `get_order_status`.
- AI calls authorized application services, not raw SQL or unrestricted internal endpoints.
- AI must not invent live price/stock/menu data; query authoritative APIs.
- User confirmation and normal backend validation are required before consequential actions; AI must never autonomously bypass payment/refund/authorization rules.
- No vector DB or RAG needed initially; add only when unstructured knowledge use cases justify it.

## 12. Coding, testing and review standards

- Backend: PHP strict types (`declare(strict_types=1)`), typed arguments/returns, enums/value objects where useful; frontend: TypeScript `strict: true`, avoid `any`, unsafe casts and magic constants.
- Small cohesive classes/functions; descriptive naming; use explicit interfaces at boundaries.
- Prefer clear code over unnecessary abstraction. Avoid overengineering and speculative features.
- Backend formatting/static analysis: Laravel Pint + PHPStan/Larastan; frontend: Prettier + ESLint + TypeScript; PSR-12/PSR-4 conventions, consistent naming.
- Tests: Pest or PHPUnit domain unit tests, application action tests, Laravel Feature/API tests, repository integration tests against PostgreSQL, payment adapter/contract tests; frontend E2E tests as needed. Do not rely on SQLite alone for PostgreSQL-specific concurrency/constraints.
- Critical test scenarios: concurrent last-item purchase, price change during checkout, repeated create-order request, duplicate manual payment confirmation, incorrect/duplicate bank transfer, late payment, invalid order transition, unauthorized confirmation, cross-store bank-account access, stock reservation expiry. Test future webhooks when implemented.
- All code changes must include relevant tests and update OpenAPI, migrations, or docs where necessary.
- Security review: injection, broken object authorization, CSRF, XSS, SSRF on external integrations, secrets leakage, rate limiting.
- Follow RESTful semantics, backward-compatible API changes, and documented deprecations.

## 13. Claude Code execution workflow

When receiving an implementation task:
1. Read `CLAUDE.md`, relevant specs, and existing code; inspect actual dependency versions.
2. Clarify only material ambiguity; otherwise state assumptions.
3. Propose a short implementation plan with affected modules, DB changes, API contract, tests and Docker impact.
4. Implement the smallest complete vertical slice, following module boundaries.
5. Add/update unit, integration and E2E tests for affected behavior.
6. Run available Laravel Pint, PHPStan/Larastan, Pest/PHPUnit, frontend lint/typecheck/build, migration validation and Compose configuration checks.
7. Review security, performance, concurrency, failure modes and store isolation.
8. Report changed files, commands run, verification results, outstanding risks and next steps.

Never claim a command/test/deploy succeeded unless it actually ran and passed. Do not silently change architecture, add new major infrastructure, or perform destructive DB operations. Request explicit approval for production deploys, destructive migrations, credential changes, and external payments.

## 14. Immediate milestone: Phase 01 — Foundation

Build only:
- Git monorepo with Next.js customer/admin workspaces (pnpm + Turborepo) and Laravel API (Composer).
- Docker multi-stage PHP-FPM API image; Nginx gateway; separate Laravel worker and scheduler entrypoints; Compose local infrastructure.
- PostgreSQL, Redis, Eloquent connection and initial Laravel migration; avoid SQLite for critical DB integration tests.
- Laravel environment/config validation, centralized exception rendering, request/correlation ID, structured logging, Form Request validation, API Resources.
- Sanctum baseline, store-scoped authorization conventions, OpenAPI `/api/v1`, `/health/live`, `/health/ready`.
- Laravel Pint, PHPStan/Larastan, Pest/PHPUnit, frontend ESLint/Prettier/TypeScript, GitHub Actions baseline.
- `.env.example`, README setup instructions, reproducible local boot and safe `php artisan migrate` workflow.

**Definition of done:** A new developer can clone, configure `.env`, start the stack using documented Docker commands, run Laravel migrations, access health/OpenAPI endpoints, and run PHP/frontend lint/static analysis/tests successfully. No AI service or speculative business features in this milestone.

## 15. Open decisions to confirm before implementing affected features

- Single-store launch vs multi-store operation and future SaaS tenancy model.
- Fulfillment modes: pickup, delivery, dine-in QR; delivery logistics provider.
- MVP payment methods are FIXED: CASH and BANK_TRANSFER with dynamic VietQR + manual staff confirmation. Still confirm bank account details, expiry window, cash collection point, and exception/refund policy; automatic reconciliation is deferred.
- Guest checkout identity/OTP policy and notification provider.
- Product stock vs ingredient-level stock, tax/fee rules, preorder cutoffs.
- Production hosting (single VPS vs managed database/Redis/storage), domains and SSL.

When these decisions are unknown, keep implementations configurable and do not invent business policy.

## 16. Laravel implementation rules and commerce extensibility

- Treat Laravel as an HTTP/queue/framework adapter, not as the domain model. Keep Domain and Application independent from Eloquent and Facades where feasible; map Eloquent persistence models to domain objects/DTOs at module boundaries.
- Register module bindings via service providers and Composer PSR-4 autoloading; route grouping and policies must follow the pinned Laravel version. Prefer constructor dependency injection and interfaces at real architectural seams, not interfaces for every trivial class.
- Use `DB::transaction()` for checkout, stock reservation, order/payment transitions and outbox writes. Use `lockForUpdate()` or conditional SQL updates to prevent overselling; add appropriate database uniqueness and CHECK constraints.
- Use Laravel Queues/Horizon with Redis and idempotent jobs. Dispatch via transactional outbox for operations that must not be lost; do not assume `afterCommit()` alone is a durable outbox.
- Use Sanctum appropriately for first-party SPA/cookie authentication, with CSRF, stateful domains, CORS, secure cookies and store-scoped Policies/Gates. Guest order lookup requires a separate secure token/access strategy.
- Use Laravel Scheduler for expiring stock reservations, payment timeout handling and periodic reconciliation tasks; only one scheduler coordinator per deployment.
- Realtime is optional in MVP. If enabled later, use Reverb/Broadcasting with authorized private channels and queue-backed event delivery.
- OpenAPI documentation may use a compatible Laravel package or generated specification; choose and pin one after evaluating maintenance and compatibility.
- Keep generic commerce concepts (`Product`, `Variant`, `Price`, `Inventory`, `Cart`, `Order`, `Payment`) in core modules. Food-specific `Menu`, `Modifiers`, `Kitchen`, `DailyPortions` are extensions. Other retail goods must be possible without a food menu or kitchen ticket. Appointment-based services require a future dedicated Booking module.
- The existing `docs/database/DATABASE_SPEC.md`, if present, is a design reference; migrate any Prisma-specific directions to Eloquent/Laravel migrations and reconcile schema differences before implementation. Do not silently generate `schema.prisma`.
- Payment MVP remains CASH and BANK_TRANSFER with dynamic VietQR and manual staff verification. Dynamic QR generation does not confirm settlement; future bank transaction webhooks/reconciliation are out of scope until approved.
