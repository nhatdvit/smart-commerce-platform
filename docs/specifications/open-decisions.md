# Open decisions

Business decisions that are not yet made. Until each one is answered, keep the
implementation **configurable** and do not invent policy
(`CLAUDE.md` §15, `docs/database/DATABASE_SPEC.md` §12).

Record the answer here with its date when it is decided, and link the
migration or module that implements it.

## Settled

| Decision                | Answer                                                                                                                                                                              | Date       |
| ----------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------- |
| MVP payment methods     | `CASH` and `BANK_TRANSFER` only. Dynamic VietQR with manual staff confirmation. No gateways, no automatic bank reconciliation.                                                      | 2026-11-09 |
| Architecture            | Modular monolith, not microservices                                                                                                                                                 | 2026-11-09 |
| Default store timezone  | `Asia/Ho_Chi_Minh`, configurable per store                                                                                                                                          | 2026-11-09 |
| Currency representation | Whole VND in `BIGINT`, decimal string over the wire                                                                                                                                 | 2026-11-09 |
| AI scope                | Not in MVP. Keep business use cases callable without HTTP.                                                                                                                          | 2026-11-09 |
| Backend stack           | PHP 8.4 + Laravel 13, Eloquent, Laravel Queues + Horizon, Sanctum. Pinned in `apps/api/composer.lock`.                                                                              | 2026-11-09 |
| Commerce scope          | Generic retail commerce is core; menus, modifiers, kitchen and daily portions are extensions. Bookings need a future module.                                                        | 2026-11-09 |
| OpenAPI generator       | `dedoc/scramble` — derives the document from Form Requests, Resources and types, so docs cannot drift from code. Pinned `^0.13.53` despite being pre-1.0; see `docs/api/README.md`. | 2026-11-09 |
| Test database           | Real PostgreSQL for every integration and feature test. SQLite is not used at any level.                                                                                            | 2026-11-09 |

## Open — blocks implementation of the named feature

### 1. Launch topology

Single store at launch, or multiple from day one? Is a SaaS tenancy model
intended later?
**Blocks:** store-context resolution strategy, catalog ownership.
**Interim:** the data model is multi-store already; store context resolves from
hostname/route plus verified membership.

### 2. Fulfillment modes

Pickup only, or pickup + delivery? Dine-in QR? If delivery, which logistics
provider, and how is the fee calculated?
**Blocks:** Fulfillment module, delivery fee in pricing, checkout UI.
**Interim:** `store_settings.pickup_enabled` / `delivery_enabled`, delivery off
by default.

### 3. Bank transfer specifics

Which bank account receives funds? What expiry window for an unpaid transfer?
Who is authorized to confirm, and what is the exception/refund policy for
underpayment, overpayment, wrong reference and late transfer?
**Blocks:** Payment module, reconciliation queue.
**Interim:** `BANK_TRANSFER_EXPIRY_MINUTES` and `TRANSFER_REFERENCE_PREFIX` are
configuration; manual confirmation is permission-gated and audited.

### 4. Cash collection point

Collected at pickup, or on delivery? May a cash order progress to `PREPARING`
before payment, and under whose confirmation policy?
**Blocks:** order state machine guards for cash orders.

### 5. Cash-order inventory policy

Reserve stock at placement, or consume immediately on confirmation? What is the
reservation timeout?
**Blocks:** Inventory module, order expiry worker.
**Interim:** recommendation is reserve at creation, consume at confirmation,
release on timely cancellation. `STOCK_RESERVATION_TTL_MINUTES` is configuration.

### 6. Scheduled orders and stock

Do future-dated orders reserve stock immediately, or only after a cutoff?
**Blocks:** preorder cutoffs, daily quota planning.

### 7. Overlapping menus and overnight schedules

If two active menus list the same variant at different prices in the same time
window, which wins? How is a schedule crossing midnight represented?
**Blocks:** Menu availability resolution.

### 8. Stock granularity

Portion-level per variant (current design), or ingredient-level with recipes?
**Blocks:** Inventory depth. Ingredient stock is explicitly deferred.

### 9. Tax and fees

Is VAT shown separately, included in the price, or not applicable? Are there
service or packaging fees?
**Blocks:** Pricing module, order totals, receipt format.

### 10. Guest identity

Is phone OTP required for guest checkout, or is a name and phone enough? How
long does guest order access survive, and what is the data-retention policy?
**Blocks:** guest order token lifetime, Notification provider choice.
**Interim:** `store_settings.allow_guest_checkout`; orders are never addressable
by id alone.

### 11. Notification provider

Which email and SMS providers? Is SMS in scope at all for MVP?
**Blocks:** Notification adapters.
**Interim:** `NOTIFICATION_EMAIL_PROVIDER` / `NOTIFICATION_SMS_PROVIDER` default
to `none`.

### 12. Partial payments

May one order be settled by more than one payment? Recommendation for MVP: **no**.
**Blocks:** payment amount validation.

### 13. Production hosting

Single VPS with self-hosted PostgreSQL/Redis, or managed services? Which
domains, and who manages TLS?
**Blocks:** `compose.prod.yaml` specifics, backup strategy, SSL automation.

## How to use this file

If a task requires one of the open answers, say so and either ask or state the
assumption you are coding against — then put the assumption behind
configuration, not a hard-coded constant.
