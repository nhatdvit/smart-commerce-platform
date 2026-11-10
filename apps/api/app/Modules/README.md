# Business modules

One directory per bounded module, each with the four layers from `CLAUDE.md` §4:

```text
<Module>/
├── Domain/{Entities,Enums,Events,Contracts}/   # business rules, no framework
├── Application/{Actions,DTOs,Services}/        # use cases, transaction boundaries
├── Infrastructure/{Persistence,Repositories}/  # Eloquent, queues, gateways
├── Presentation/{Controllers,Requests,Resources}/
└── <Module>ServiceProvider.php
```

Providers are registered explicitly in `bootstrap/providers.php`, not
auto-discovered, so the set of active modules is one readable list.

## Rules

Enforced by `tests/Unit/ArchitectureTest.php` (run by Pest, so CI fails on a
violation):

- `Domain/` may not use `Illuminate\Database\Eloquent\Model`,
  `Illuminate\Http\Request` or `Illuminate\Support\Facades`.
- `Presentation/` may not touch persistence models directly.
- Every class declares `strict_types=1`.
- No `dd`, `dump`, `var_dump`, `die` or `exit` anywhere.

Enforced by review:

- Laravel is an adapter, not the domain model. Map Eloquent rows to domain
  objects or DTOs at the module boundary (`CLAUDE.md` §16).
- No module reads another module's tables or repositories. Go through its
  exported application service, a published contract, or a domain event.
  `Identity` exposes `ChecksStorePermissions`; `Store` exposes `StoreContext`.
- Use cases are callable without HTTP, so a future AI tool invokes the same
  validated operation a controller does (`CLAUDE.md` §11).
- `DB::transaction()` for checkout, stock reservation, order and payment
  transitions, and outbox writes — business state and its outbox row commit
  together or not at all.

## Built in this milestone

| Module     | What exists                                                                                          |
| ---------- | ---------------------------------------------------------------------------------------------------- |
| `Identity` | Users, roles, permissions, store membership; `StorePermissionChecker` and the `store.can` middleware |
| `Store`    | Stores, settings, hours, closures; `StoreContext` and the `store.resolve` middleware                 |

Cross-cutting code lives in `app/Shared/`: `Money`, `ErrorCode`,
`BusinessRuleViolation`, the request-id middleware, the exception renderer, the
environment validator, and the audit/outbox/idempotency models.

## Not built yet

`Catalog`, `Menu`, `Inventory`, `Cart`, `Pricing`, `Order`, `Payment`,
`Fulfillment`, `Kitchen`, `Customer`, `Notification`, `Reporting`, `Audit`.

They are deliberately absent rather than present as empty scaffolds
(`CLAUDE.md` §4). Add a module when its milestone starts, and register its
provider in `bootstrap/providers.php` at the same time.

## Core versus extension

`CLAUDE.md` §16 separates generic commerce from food specifics:

- **Core**: `Product`, `Variant`, `Price`, `Inventory`, `Cart`, `Order`,
  `Payment`. Selling retail goods must be possible with these alone.
- **Extension**: `Menu`, `Modifiers`, `Kitchen`, daily portions. A store that
  sells packaged goods should need no menu and produce no kitchen ticket.

Appointment-based services are out of scope and would need a future `Booking`
module rather than being bent into `Menu`.
