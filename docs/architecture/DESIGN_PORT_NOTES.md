# Design port notes — storefront and admin UI

How the two delivered design packages were brought into the monorepo, and every
place the result deviates from them.

| Design package                  | Target app          |
| ------------------------------- | ------------------- |
| `phu-quy-food-storefront-v5`    | `apps/customer-web` |
| `breakfast-admin-responsive-v1` | `apps/admin-web`    |
| `bep-nha-minh-storefront`       | `apps/customer-web` |
| `my-orders-v2`                  | `apps/customer-web` |

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

### 7. The storefront uses Be Vietnam Pro, not the design's font stack

The delivered rule was `font: 14px/1.5 Inter, Arial, 'Segoe UI', sans-serif`, but
the package never loaded Inter, so the site rendered in Arial — whose Vietnamese
diacritics sit badly on `ă ơ ư` and on stacked tone marks. Requested by the
product owner. Be Vietnam Pro is drawn for Vietnamese and is already what the
admin app uses, so the two apps now match. Only the family is overridden; the
delivered size and line-height still apply.

**Visual effect:** intended — Vietnamese text is noticeably better set.

### 8. Emoji and symbol characters replaced by an SVG icon set

The design used emoji and symbol characters as icons — `🥖 🍜 🥤 🔥 ⭐ 🛵 🌿 🛡️
🎁 🎟️ 🛒 🛍️ ♨️ ⌕ ♟ ⌂ ☷ ☆`, and `♡` / `♥` for the favourite button. None of
them is in the body font, so each platform substitutes its own: they arrive at
different sizes, weights and colours, several render as full-colour emoji that
read as clip art rather than interface, and on Windows the heart came back
oversized and off-centre in its 27px button.

`components/storefront/icons.tsx` replaces them with one line set — a 24×24
grid, stroked in `currentColor`, sized by the component rather than by
`font-size`. Each icon takes the colour the design already gives its container,
so the palette is unchanged. Requested by the product owner.

**Visual effect:** intended — one coherent monochrome icon set instead of mixed
emoji. Shapes and positions follow the design; only the rendering changed.

The admin app got the same treatment, from the same set: the sidebar, topbar,
metric tiles, page-action buttons, coupon cards and the product thumbnails that
were food emoji. `DemoProduct.emoji` / `DemoReward.emoji` became
`icon: FoodIconName`, so a product is tagged with a category rather than with a
character. The two apps now share one visual language.

One trap, hit once and worth recording: the alignment rules for these icons are
appended at the end of each stylesheet, **after** the media queries. Setting
`display` on `.header-cart` or `.login` there silently beat the storefront
design's `display:none` below 760px and brought both back on mobile. They are
aligned with `vertical-align` instead. The admin stylesheet was written with the
same constraint from the start — nothing in its appended block sets `display` on
`.icon-button`, `.menu-toggle`, `.close-nav`, `.crumb` children, `.live-pill`,
`.overlay` or `.sidebar` — and both files carry a comment saying why.

### 9. The ☕ watermark on the admin welcome card

The design decorated that card with `content:'☕'` at 135px and 7% opacity — a
giant emoji watermark, and on Windows a full-colour one. Replaced with a soft
radial bloom in the same position and weight.

**Visual effect:** intended — the same quiet highlight, without the clip art.

### 10. Two pieces of dashboard copy

`"Chào buổi sáng! ☀️"` was a fixed greeting regardless of the actual time, and
`"Hôm nay bán thật đắt hàng nhé! 🥐"` read as filler. They are now
`"Tổng quan hôm nay"` and `"Bắt đầu ca sáng"`. Requested by the product owner as
part of making the screens look designed rather than generated; the rest of the
Vietnamese copy is the client's voice and is untouched.

### 9. Storefront search and the cart drawer are React-controlled

The prototype re-rendered the search field from a string and restored the caret
by hand; the drawer re-rendered its own markup on every cart change. Both are
ordinary controlled React components now. Identical behaviour, fewer moving
parts.

## The delivered photos were mockup crops, and have been re-cropped

`apps/customer-web/public/assets/food-*.jpg` arrived as **crops of a mockup
screenshot**, not photographs. Each had the storefront's own UI baked into its
pixels: a white circular favourite button with a red heart in the top-right
corner, and a rounded white card border around the edge. The visible result was
two hearts on every product card — the real clickable button, and a second one
painted into the image beneath it. The design package's own README said as much:
_"Ảnh sản phẩm được cắt từ bản mockup thiết kế, chỉ dùng làm ảnh demo. Nên thay
bằng ảnh món ăn thật khi triển khai."_

All 13 were re-cropped to remove it. The badge measured consistently at
x 394–531, y 2–105 of a 534×321 source, so cropping the **right** strip clears it
while keeping each dish framed vertically; cropping the top strip would have cut
into the food and left an aspect ratio the card then crops again. The inset also
clears the rounded white frame, whose corner radius is wider than the frame is
thick.

- Food: `534×321` → `372×283`, crop `left 16, top 22`
- Hero: `640×260` → `620×240`, a 10px inset (no badge, frame only)

They are also much smaller — `food-1.jpg` went from 70 KB to 19 KB.

This is still a repair of demo assets, not a substitute for real photography.
The images are AI-generated and look it; replacing them is the remaining job,
and when that happens the card should own its corner radius rather than the
image. The script used is not checked in — it ran once, from the scratchpad.

## Brand: "Bếp nhà mình"

The client supplied a logo after the port: one 1536×1024 lockup, the
house-and-pot emblem above, the "Bếp nhà mình" wordmark and "MÓN NGON NHÀ LÀM"
tagline below. The storefront header is 80px tall (62px on a phone) and the
admin sidebar gives the mark a 41px slot, so the whole lockup cannot be shown
legibly in either. It is split instead:

- `public/brand/logo-mark.webp` — the emblem, for the header and sidebar
- `public/brand/logo.webp` — the full lockup, for anywhere with room
- `src/app/icon.png` — the emblem squared on transparency, which Next's App
  Router serves as the favicon

The wordmark is set as **live text** beside the emblem rather than baked into
the image, so it stays crisp at any zoom, reflows on a phone and is readable to
a screen reader. The emblem/wordmark cut is at y=618, measured: the yellow
spoon sweep ends at ~615 and the wordmark's accent marks start at ~620.

The brand name changed with it, from "Phú Quý" / "Bữa Sáng Phú Quý":

| Where                                      | Now                                     |
| ------------------------------------------ | --------------------------------------- |
| Storefront header                          | Bếp nhà mình / Món ngon nhà làm         |
| Storefront `<title>`                       | Bếp nhà mình — Món ngon nhà làm         |
| Storefront footer                          | © 2026 Bếp nhà mình · Món ngon nhà làm |
| Admin sidebar                              | BẾP NHÀ MÌNH / Commerce Admin           |
| Admin `<title>`                            | Bếp nhà mình Admin — Quản lý bán hàng   |
| Admin store card and storefront-config row | Bếp nhà mình                            |

The admin's yellow "PQ" tile is gone — the emblem carries its own colour, and
keeping the tile would have boxed a logo inside a second logo.

### Resolved: the hero photo showed the old brand

`apps/customer-web/public/assets/hero.jpg` was one of the AI-generated mockup
images, and the coffee cup in it had a **"Phú Quý" logo painted into the
pixels**, contradicting the new header logo. Fixed by the `bep-nha-minh-storefront`
redesign below, which delivered a hero photo with the correct cup branding.

## Homepage redesign v2 (`bep-nha-minh-storefront`)

A second design pass arrived later, as another plain HTML/CSS/JS package, over
the same v5 markup: a lighter, airier palette, a full-bleed hero and closing
banner, a horizontally-scrolling category row, a 4-column product grid, and a
restyled right column. Ported the same way as v5 — the delivered CSS appended
to `storefront.css` as its own block rather than rewritten into the rules it
supersedes (see that block's own header comment) — plus the structural change
the delivered markup also made: `.hero` and `.delivery` moved out of
`.main-column` to become full-width siblings of it and `.right-column` inside
`.page` (`grid-column:1/-1`). Confirmed live, scrolled, not just against the
static mock.

### The hero photo

The package's own `assets/hero.jpg` turned out to be the _old_, still
"Phú Quý"-branded mockup crop byte-for-byte (same 640×260 the first port
fixed, re-included by mistake). The correct photo — "Bếp nhà mình" on the cup
— only existed baked into a result screenshot, with a prev/next arrow and dot
carousel control painted over it. Re-cropped with `sharp` (not checked in,
same one-off-script convention as the food photos above):

- Source `552×315` → extract `left 60, top 50, width 432, height 215` clears
  the arrows, the dots and a decorative corner shape bleeding in from the
  page behind it, keeping the branded cup centred.

### Deviations from this second package

1. **The delivered `.cart-panel{position:sticky;top:84px}` is dropped.** Cart,
   loyalty, promo and trust are four stacked panels in the same column, so a
   sticky first panel rides over the other three as the page scrolls, rather
   than leaving room for them underneath — visible on the first scroll, not
   on a static screenshot. The cart panel scrolls in flow instead.

   **Visual effect:** none at rest; the sticky behaviour never shipped.

2. **The delivery banner's rider-on-a-scooter illustration is a line-icon
   badge, not artwork.** Consistent with item 8 above: the rest of the page
   is one coherent icon set, and a one-off cartoon illustration was not part
   of either delivered CSS/HTML file, only visible in the result screenshot.

   **Visual effect:** close, not identical — a circular badge around the
   existing scooter icon rather than a custom drawing.

3. **The header nav keeps "Tích điểm" (→ the rewards panel), not "Về chúng
   tôi."** The result screenshot's nav reads differently, but the delivered
   `index.html`/`app.js` in this same package still jump to `#rewards` with
   the old label, and there is no "about us" page or section anywhere to
   link an "about us" item to. Added small icons in front of each label
   either way, which both the screenshot and the old base design lacked.

4. **The loyalty/rewards panel is kept in the right column.** The result
   screenshot (captured signed in) shows only cart → promo → trust, no
   rewards panel in between — but the request's own scope was icons, colour
   and type, not removing a working feature, and the delivered markup still
   includes it at that position. Only promo and trust were restyled.

5. **Section heading icons still cycle (flame/star/cup by position), not by
   category name.** The screenshot pairs specific icons to specific category
   names (a leaf for "Chả đặc sản", for instance), but categories are live
   API data here, not a fixed list — a name→icon table would silently stop
   matching the day a category is renamed or added. Left as the existing,
   already-documented "purely for visual variety" cycle.

**Visual effect of 3-5:** faithful to the interactive package; a few
cosmetic details from the static result screenshot were not chased where
doing so meant inventing content or coupling to data that is not fixed.

## "Đơn hàng của tôi" redesign (`my-orders-v2`)

The order-history route (`OrdersPage.tsx`) went from a flat list to the
delivered layout: a dark sidebar (account card, nav list, loyalty promo),
a hero banner, five status tabs with live counts, search/date/sort filters
and a five-per-page pager - all new, over the full order history (a real
shop's is small, same reasoning as the homepage's product-count comment),
so counts, search and sort are correct across every order, not just the
page the API happened to return first.

Everything that was already real stayed real and got no less capable:
cancel now asks for the required reason the backend's `CancelOrder` already
accepted but the old UI never collected; reorder is unchanged
(`getOrder` + `addToStoredCart`); and "Thanh toán ngay" is a genuine
addition - `GET /orders/{id}/payment` (already used right after checkout,
now also reachable from history) backing the exact same QR/bank-details/
report-transfer flow, factored out of `ModalLayer.tsx` into
`payment-widgets.tsx` (`BankTransferPanel`, `QrCode`, `CopyButton`,
`TransferReportForm`) so both places render the identical, real thing
rather than a second implementation.

### Deviations

1. **No star ratings.** The delivered demo lets a customer rate a completed
   order (stored to its own `localStorage` key, nothing server-side). There
   is no review/rating module anywhere in CLAUDE.md's module table or the
   codebase - adding the button would mean either inventing a fake
   persisted-looking feature or a real one with no backend to receive it.
   Left out entirely rather than shipping either.

2. **Cancel stays available for a confirmed order, not just a pending one.**
   The demo's own cancel button only ever appears on its `pending` bucket.
   The real rule (`CancelOrder::CUSTOMER_CANCELLABLE`, already wired before
   this redesign) also allows cancelling a `CONFIRMED` order, before the
   kitchen starts - narrowing the button to match the demo would have taken
   away a capability customers already had.

3. **Most sidebar nav items are inert**, same as the delivered demo's own
   `.menu` rows (plain, unwired `<div>`s in its markup): "Thông tin tài
   khoản", "Sổ địa chỉ giao hàng" and "Mã giảm giá của tôi" have no page or
   feature behind them anywhere in this app. "Đăng xuất" is real
   (`logoutCustomer`) since leaving a prominent, labelled control dead would
   be a worse regression than the demo's own non-interactivity; "Điểm
   thưởng" shows the real balance (`getOwnLoyalty`) inline instead.

**Visual effect:** faithful to the design; the one feature gap (ratings) is
a backend gap, not a styling shortcut.

## Not ported (absent from the delivered designs)

- Customer authentication. The login button opens the design's own placeholder
  dialog.
- Admin authentication and RBAC. The design has no login screen; the shell is
  open. Store-scoped authorization is a backend milestone (CLAUDE.md §8), and
  the admin host is already restricted at the gateway.
- Realtime order updates, kitchen display, VietQR rendering. All later phases.

## Admin dashboard redesign (2026-11-25)

The admin dashboard (`/`) was redesigned away from the delivered
`breakfast-admin-responsive-v1` layout. The spec, the audit it came from and
the decisions that were approved before any code was written are in
[`docs/specifications/admin-dashboard-redesign.md`](../specifications/admin-dashboard-redesign.md).

What this means for the port:

- **The delivered dashboard is gone.** The welcome banner, the four tinted
  `.metric` cards, the hardcoded bar chart and the "Món bán chạy" list were
  replaced by components under `components/admin/dashboard/`, styled by a new
  `styles/dashboard.css` that loads after `admin-shell.css`. Every other admin
  screen still renders the delivered design unchanged.
- **The tokens in `admin-shell.css` moved**, which is the one change that
  reaches every screen. `:root` now carries the approved palette (navy
  `#14243b`, gold `#f5b82e`, line `#e5e9f0`, success `#059669`, error
  `#dc2626`) plus the spacing, radius and duration scales the dashboard is
  built from. The shifts are a few percent per hue; nothing was re-laid-out.
- **Five text colours were darkened for contrast**, not for looks: the status
  badges, the "see all" links, the table action and the active sidebar row
  were all between 3.2:1 and 3.7:1 against their own backgrounds at 10-11px.
  They now clear 4.5:1. The hues are unchanged.
- **The data is real.** `src/data/admin-demo.ts` was deleted. Every figure on
  the dashboard is derived from `/admin/orders` over a store-local date window
  and from the menu snapshot; the figures that cannot be derived honestly are
  absent, and listed as backend requests in the spec.
- **`ProductThumb` falls back to its icon when a photo fails to load.** The
  seeded demo products carry storefront-relative paths (`/assets/food-N.jpg`)
  that exist only in `apps/customer-web/public`, so on the admin host every one
  of them 404s. The old component rendered the alt text instead, which pushed
  a Vietnamese dish name out of its 38px box and across the panel below it.
