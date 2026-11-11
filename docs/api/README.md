# API conventions

Base path `/api/v1`. Health probes sit outside the versioned prefix so
orchestrators never depend on the business contract.

## Status codes

| Code            | Used for                                                          |
| --------------- | ----------------------------------------------------------------- |
| 200 / 201 / 204 | Success, created, success with no body                            |
| 400             | Malformed request, or a store context that could not be resolved  |
| 401             | Not authenticated                                                 |
| 403             | Authenticated but not permitted, including store-scope denial     |
| 404             | Not found, or not visible to this caller                          |
| 405             | Method not allowed                                                |
| 409             | Conflict: invalid transition, out of stock, idempotency key reuse |
| 422             | Semantically invalid request that parsed correctly                |
| 429             | Rate limited                                                      |

## Error body

Every failure returns the same shape, produced by
`App\Shared\Infrastructure\Exception\ApiExceptionRenderer`:

```json
{
  "status_code": 409,
  "code": "OUT_OF_STOCK",
  "message": "Not enough stock remains for this item",
  "details": [{ "field": "items.0.quantity", "message": "only 2 remain" }],
  "request_id": "0f8d2c7a4b1e4f9a8c3d2e1f0a9b8c7d",
  "timestamp": "2026-11-09T01:12:33Z",
  "path": "/api/v1/orders"
}
```

`code` is a case of `App\Shared\Presentation\Http\ErrorCode` — stable and
machine-readable. Clients branch on it, never on `message`. Adding a case is
safe; repurposing one is a breaking change. `details` appears only for
validation failures. A `debug` key appears only when `APP_DEBUG` is on and only
for genuinely unexpected errors.

Stack traces, SQL and secrets never appear in a response. 404 is returned for a
resource that exists but belongs to another store, so the API cannot be used to
enumerate objects.

Domain and application code throws
`App\Shared\Domain\Exception\BusinessRuleViolation` with an `ErrorCode`, not an
HTTP exception — the rule stays independent of the transport.

## Money

VND amounts are whole đồng, and every amount is an **object** carrying its own
currency, with the value as a decimal string:

```json
{
  "unit_price": { "amount": "45000", "currency": "VND" },
  "line_total": { "amount": "135000", "currency": "VND" }
}
```

Two separate reasons, both of which have burned real systems:

- JSON numbers cannot hold a 64-bit integer safely in every client, so the API
  never sends a numeric amount.
- An amount without its currency invites a client to format it with the wrong
  one, and keeps the shape from surviving a second currency later.

Server-side, amounts are `App\Shared\Domain\ValueObject\Money` — an integer and
a currency, with exact arithmetic. Client-side the matching type is `Money` in
`packages/api-contracts/src/money.ts`, with `toMoney` / `fromMoney` for the
`bigint` conversion. Clients must not parse amounts into floats.

Request bodies are the one exception: a field that _sets_ a price takes a bare
string named `*_amount` (`selling_price_amount`, `base_price_amount`), because
the currency is the store's and is not the client's to choose.

## Headers

| Header                | Direction | Meaning                                                                                                                                          |
| --------------------- | --------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| `X-Request-Id`        | both      | Correlation id. Echoed on every response; appears in logs and audit rows. An inbound value is reused only if it matches `[A-Za-z0-9._:-]{1,128}` |
| `Idempotency-Key`     | request   | **Required** on order creation. The stored result is replayed on retry                                                                           |
| `X-Guest-Cart-Token`  | request   | Guest access to a cart. Issued once by `POST /carts`, never re-issued                                                                            |
| `X-Guest-Order-Token` | request   | Guest access to an order. Orders are never addressable by id alone                                                                               |
| `X-XSRF-TOKEN`        | request   | CSRF token for cookie-authenticated calls                                                                                                        |

## Authentication

First-party web apps use **Sanctum cookie authentication**: call
`GET /sanctum/csrf-cookie`, then send credentials with `withCredentials`. This
requires the origin to be listed in both `SANCTUM_STATEFUL_DOMAINS` and
`CORS_ALLOWED_ORIGINS`, and `supports_credentials` is on, so a wildcard CORS
origin is impossible by construction.

Guest order lookup is a separate mechanism: a scoped token on the order, not a
session.

Unauthenticated API requests return 401 with the envelope regardless of the
`Accept` header — `Authenticate::redirectUsing()` is overridden to return null,
because an API has no login page to redirect to.

## Authorization: store scope

Admin roles: `SUPER_ADMIN`, `STORE_OWNER`, `STORE_MANAGER`, `CASHIER`,
`KITCHEN_STAFF`. Customer access is scoped separately.

**Every admin operation checks two things**: the role grants the permission,
_and_ the grant applies to the target store.

```php
Route::put('/admin/stores/{store}/bank-account', [BankAccountController::class, 'update'])
    ->middleware(['auth:sanctum', 'store.resolve', 'store.can:payment.bank-account.manage']);
```

Store context is resolved from the route parameter, a configured host mapping,
or the single-store default — never from a query parameter. A client that sends
`?store_id=` for a store it does not staff gets 403, not data.

Permission codes are seeded by `RolePermissionSeeder`; `STORE_MANAGER`
deliberately lacks `payment.bank-account.manage`, since changing where money
lands is an owner-level action.

## Validation

Input validation belongs in Form Requests, output shaping in API Resources, and
orchestration in application Actions. Controllers stay thin. Validation
failures surface as 422 with per-field `details`. Payload size is capped at the
gateway (`client_max_body_size`) and in PHP (`post_max_size`).

## Pagination

Cursor-based, for every list endpoint:

```http
GET /api/v1/admin/orders?limit=20&cursor=eyJpZCI6...
```

```json
{ "items": [], "next_cursor": "eyJpZCI6...", "has_more": true }
```

Offset pagination is not used — it drifts and degrades on large, frequently
written tables.

## Rate limiting

Two layers. Nginx sheds obvious floods (20 r/s general, 2 r/s on `/orders`,
`/carts`, `/payments`), and Laravel applies named limiters from
`config/commerce.php`: `api` for reads, `commerce-write` for writes, and `login`
limited per credential _and_ per IP so neither a single account nor a single
host can be brute-forced cheaply.

## OpenAPI

There are **two** documents, and the difference between them is the point.

| File                           | Is                             | Written by                |
| ------------------------------ | ------------------------------ | ------------------------- |
| [`openapi.yaml`](openapi.yaml) | what the API is designed to be | a person, before the code |
| [`openapi.json`](openapi.json) | what the API currently is      | dedoc/scramble, from code |

`openapi.yaml` is the **design authority**. An endpoint is specified there,
reviewed as a design, and only then implemented. It carries the reasoning as
comments — why a cart line references a menu item and not a variant, why a price
is never accepted from a client, why confirming a payment takes an amount
explicitly — because a contract that records only its shape loses the argument
that produced it.

`openapi.json` is generated from the code itself: Form Requests, API Resources
and type hints. It is committed so the contract is reviewable in a diff and
usable without booting the stack, and it is served at `/docs/api` in
non-production environments. Regenerate it in the same change as the code:

```bash
pnpm api:openapi
```

### How the two are kept honest

```bash
pnpm openapi:conformance
```

`infra/scripts/check-openapi-conformance.mjs` compares them. The rule is
**conformance, not equality**, in one direction only:

| Situation                                                   | Result |
| ----------------------------------------------------------- | ------ |
| An operation exists in the code but not in the design       | fails  |
| An implemented operation answers with a status not designed | fails  |
| The two disagree about `info.version`                       | fails  |
| A `$ref` in the design does not resolve                     | fails  |
| An operation is designed but not yet implemented            | passes |

The last row is why equality would be the wrong rule: for most of this
project's life the design is ahead of the code, and a check that demanded
equality would fail the first commit of every new contract. Unbuilt operations
are reported as the remaining backlog instead.

CI runs it in the `contract` job, which needs neither a database nor a running
stack — both inputs are committed files. Its companion is the "OpenAPI document
is current" step in `php-tests`, which proves `openapi.json` still matches the
code. Together: the code matches what was generated, and what was generated
matches what was designed.

### Why Scramble generates the second document

Per `CLAUDE.md` §16 ("choose and pin one after evaluating maintenance and
compatibility"): annotation-based generators such as `darkaonline/l5-swagger`
are mature at 1.x, but their annotations drift from the code, which is exactly
the failure this project cannot afford for a payment API. Scramble derives the
document from the types that actually execute. The trade-off is that it is
pre-1.0 (`^0.13.53`), so it is pinned to a minor range and upgrades are
reviewed.

Having a hand-written design document does not make Scramble redundant — it is
what turns the design into something enforceable rather than aspirational.

`pnpm api:openapi` wraps `infra/scripts/export-openapi.sh`, which exports inside
the api container and copies the result out. It deliberately does not fetch
through the gateway: a relative server URL is resolved against the request host,
so a document exported that way would claim the API lives on whichever hostname
was used. `config/scramble.php` pins `api_path` to `api/v1` and lists the servers
explicitly, so the committed file is host-independent. Health probes sit outside
that prefix and are intentionally absent from both documents — they are an
orchestration contract, not part of the business API.

## Endpoint inventory

[`openapi.yaml`](openapi.yaml) is the authority; this is a map of it. Route
strings live in `packages/api-contracts/src/routes.ts` so the two web apps
cannot drift from each other.

The designed MVP surface is 46 operations. `CLAUDE.md` §8 names 15 of them — the
rest are what those 15 need to make a store actually operable: reading and
editing a cart, managing the catalogue, setting daily portions, and the
reconciliation actions that manual payment verification requires.

| Area              | Operations | Notes                                                |
| ----------------- | ---------: | ---------------------------------------------------- |
| Meta, Auth        |          4 | `/`, `/me`, login, logout                            |
| Store             |          1 | Profile, hours, offered fulfillment                  |
| Catalog (public)  |          3 | Categories, product search, product detail           |
| Menu (public)     |          1 | `menus/today` — the only authority on what sells now |
| Cart              |          6 | Create, read, discard, add, update, remove           |
| Order             |          3 | Place (Idempotency-Key), read, cancel                |
| Payment (public)  |          2 | Methods, and the VietQR / cash instructions          |
| Customer          |          6 | Own orders, profile, saved addresses                 |
| Admin — Orders    |          3 | Queue, detail, validated transition                  |
| Admin — Payments  |          4 | Reconciliation queue, detail, confirm, flag          |
| Admin — Store     |          3 | Bank account read and write, payment methods         |
| Admin — Menu      |          4 | List, detail, replace, sold-out toggle               |
| Admin — Catalog   |          3 | List, create, update                                 |
| Admin — Inventory |          2 | Daily portions                                       |

17 schemas and operations carry `x-provisional: true`. They depend on a business
decision still open in `CLAUDE.md` §15 — delivery and fee rules, guest identity
and OTP, tax, and whether stock is counted per finished product or per
ingredient. They are designed configurably rather than invented, and the marker
is how to find every place a decision will land:

```bash
grep -n 'x-provisional' docs/api/openapi.yaml
```

Phase 01 implements `GET /api/v1/` and `GET /api/v1/me`, plus the `/health/live`
and `/health/ready` probes that sit outside the versioned API. Everything else
is designed and not yet built; `pnpm openapi:conformance` prints the exact
remaining list.

## Changing the contract

Additive changes only within `v1`: new optional fields, new endpoints, new error
codes. Removing or renaming a field, tightening a type, or changing the status
code for an existing case is breaking — version it and document the deprecation
window. Update the OpenAPI document and the route table in the same change as
the code.
