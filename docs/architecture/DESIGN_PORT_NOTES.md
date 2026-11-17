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

### Outstanding: the hero photo still shows the old brand

`apps/customer-web/public/assets/hero.jpg` is one of the AI-generated mockup
images, and the coffee cup in it has a **"Phú Quý" logo painted into the
pixels**. With the new logo in the header, the hero now contradicts it. No code
change can fix that; the cup is the centre of the composition, so it cannot be
cropped out either. It needs a replacement hero image — the same job as the
product photography above.

## Not ported (absent from the delivered designs)

- Customer authentication. The login button opens the design's own placeholder
  dialog.
- Admin authentication and RBAC. The design has no login screen; the shell is
  open. Store-scoped authorization is a backend milestone (CLAUDE.md §8), and
  the admin host is already restricted at the gateway.
- Realtime order updates, kitchen display, VietQR rendering. All later phases.
