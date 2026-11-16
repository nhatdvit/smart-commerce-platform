# Design port notes — storefront and admin UI

How the two delivered design packages were brought into the monorepo, and every
place the result deviates from them.

| Design package                  | Target app          |
| ------------------------------- | ------------------- |
| `phu-quy-food-storefront-v5`    | `apps/customer-web` |
| `breakfast-admin-responsive-v1` | `apps/admin-web`    |

Both packages were plain HTML, CSS and vanilla JavaScript — no build tool, no
`package.json`, no dependencies. They were unpacked outside the repository and
read before anything was copied in; nothing from either archive was executed.

## Approach

The design of record is the delivered CSS, copied into the repo essentially
verbatim:

- `apps/customer-web/src/styles/storefront.css`
- `apps/admin-web/src/styles/admin-shell.css`

The markup was rewritten as React components and the behaviour as React state,
keeping the delivered class names, DOM structure and interaction flow. The CSS
was **not** rewritten into Tailwind utilities — see the first deviation below.

Each stylesheet loads after `globals.css`, so where the Tailwind preflight and
the design disagree, the design wins. Tailwind, the shared preset and the
shadcn/ui tokens stay configured for components built from here on.

## Data

Every figure on both apps is static demo data, which is correct for this stage:
the API has 2 of its 46 designed operations built and no business endpoints at
all. There is deliberately **no API client and no fetch call** — nothing
pretends to talk to an endpoint that does not exist. The seams to replace are
single files:

- `apps/customer-web/src/data/products.ts` — catalogue, variants, prices
- `apps/admin-web/src/data/admin-demo.ts` — orders, products, coupons, rewards,
  customers, chart series

Customer cart, coupon and loyalty state live in `localStorage`, as the delivered
prototype's did. This is presentation state only; the server revalidates price,
availability and totals at checkout and never trusts it (CLAUDE.md §6).

## Deviations from the delivered design

### 1. The CSS was kept as hand-written CSS, not converted to Tailwind

CLAUDE.md §2 lists Tailwind + shadcn/ui as the UI stack. Translating ~27 KB of
delivered CSS — including four storefront and three admin breakpoints, gradients,
`clamp()` type scales and a `repeating-linear-gradient` chart grid — into utility
classes would have meant re-deriving every value by hand, and "stay faithful to
the design" was the overriding requirement. Keeping the designer's stylesheet
makes the port exact and reviewable as a diff against the source file.

**Visual effect:** none. Tailwind remains available for new work.

### 2. Product photos use `<img>`, not `next/image`

The storefront stylesheet positions the hero photo with `position:absolute` plus
`object-fit:cover`, and sizes card, cart and reward thumbnails by class. The
wrapper element `next/image` injects changes that geometry. The photos are also
local demo crops, not remote media, so the loader buys nothing yet.

**Visual effect:** none. Revisit when product media comes from Storage.

### 3. Admin screens are real routes, not an in-place page swap

The prototype held `page` in a variable and re-rendered `main` from a registry of
render functions; the URL never changed. Each screen is now a route under
`app/` (`/`, `/orders`, `/menu`, `/inventory`, `/payments`, `/promotions`,
`/loyalty`, `/customers`, `/storefront`, `/settings`), so the browser URL, the
back button and deep links work. Demo state lives in a provider in the layout,
above the router, so edits survive navigation exactly as before.

**Consequence:** the sidebar rows and the panel "see all" links are now `<Link>`
anchors rather than `<button>`. Three edits to `admin-shell.css` keep them
looking identical — recorded in that file's header:

1. `nav button{…}` now reads `nav a, nav button{…}`, plus `text-decoration:none`.
2. Anchor-compatibility rules appended at the end of the file, giving
   `.panel-title a` the styling `.panel-title button` already had.
3. The `@import` of Be Vietnam Pro from Google Fonts was removed from the
   stylesheet and is loaded by `<link>` in the layout instead. A bundled
   stylesheet can place an `@import` after other rules, where browsers ignore it.
   `next/font` was not used, because it would make the Docker image build depend
   on reaching Google at build time. The stylesheet still falls back to Arial.

**Visual effect:** none.

### 4. The admin date eyebrow appears after hydration

Every admin page title carries today's date, formatted `vi-VN`. Both apps are
statically prerendered, so formatting it during the server render would bake the
build date into the HTML and then disagree with the browser. It is resolved in an
effect instead (`useTodayLabel`).

**Visual effect:** the eyebrow line is blank for the first paint of a cold load.

### 5. Money is formatted by hand, not with `toLocaleString('vi-VN')`

Same reason: any difference between the server's and the browser's ICU data would
surface as a hydration mismatch. VND has no minor unit, so integer grouping with
a dot separator reproduces the `vi-VN` output exactly (`src/lib/money.ts`).

**Visual effect:** none.

### 6. One dead control in the admin prototype was wired up

The promotions screen rendered a "Chi tiết" button per coupon with a
`data-coupon` attribute, but the prototype's click handler did not list that
attribute, so the button did nothing. It now opens a read-only coupon detail
modal in the same style as the other dialogs. This is the one place where
behaviour was added rather than ported; it looked like an oversight in the
prototype, not an intentional no-op.

### 7. Storefront search and the cart drawer are React-controlled

The prototype re-rendered the search field from a string and restored the caret
by hand; the drawer re-rendered its own markup on every cart change. Both are
ordinary controlled React components now. Identical behaviour, fewer moving
parts.

## Not ported (absent from the delivered designs)

- Customer authentication. The login button opens the design's own placeholder
  dialog.
- Admin authentication and RBAC. The design has no login screen; the shell is
  open. Store-scoped authorization is a backend milestone (CLAUDE.md §8), and
  the admin host is already restricted at the gateway.
- Realtime order updates, kitchen display, VietQR rendering. All later phases.
