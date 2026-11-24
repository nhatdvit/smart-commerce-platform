/**
 * Storefront icon set.
 *
 * The delivered design used emoji and symbol characters as icons (🥖 🍜 🥤 🔥
 * ⭐ 🛵 ⌕ ♟ ☷). Every platform substitutes its own font for those, so they
 * arrive at different sizes, weights and colours - and on Windows several of
 * them render as full-colour emoji that read as clip art rather than as part
 * of the interface. These are one coherent line set instead: a 24x24 grid,
 * stroked in `currentColor`, so each icon takes the colour the design already
 * gives its container.
 *
 * Sizes are passed explicitly rather than inherited from `font-size`, so the
 * stylesheet's type scale no longer decides how big an icon is.
 */
import type { ReactNode } from 'react';

interface IconProps {
  /** Rendered width and height in px. */
  readonly size?: number;
}

function Icon({ size = 24, children }: IconProps & { readonly children: ReactNode }) {
  return (
    <svg
      className="storefront-icon"
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.8}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      {children}
    </svg>
  );
}

/** Brand mark: a steaming bowl. Replaces the ♨️ hot-springs emoji. */
export function IconBrand({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M3 11h18a9 9 0 0 1-9 9 9 9 0 0 1-9-9Z" />
      <path d="M2 11h20" />
      <path d="M9 3c-1 1.3-1 2.4 0 3.7" />
      <path d="M13.5 2.4c-1.2 1.5-1.2 2.9 0 4.4" />
    </Icon>
  );
}

// ---------------------------------------------------------------- categories

export function IconGrid({ size }: IconProps) {
  return (
    <Icon size={size}>
      <rect x="3.5" y="3.5" width="7" height="7" rx="2" />
      <rect x="13.5" y="3.5" width="7" height="7" rx="2" />
      <rect x="3.5" y="13.5" width="7" height="7" rx="2" />
      <rect x="13.5" y="13.5" width="7" height="7" rx="2" />
    </Icon>
  );
}

/** Bánh mì. */
export function IconBaguette({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M3.5 13.5c0-3.6 3.8-6.5 8.5-6.5s8.5 2.9 8.5 6.5" />
      <path d="M3.5 13.5c0 2 1.6 3.5 3.5 3.5h10c1.9 0 3.5-1.5 3.5-3.5" />
      <path d="M3.5 13.5h17" />
      <path d="M8.2 9.6 9.6 12" />
      <path d="M13 9.1 14.4 11.5" />
    </Icon>
  );
}

/** Chả đặc sản: slices on a skewer. */
export function IconSkewer({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M4 20 20 4" />
      <circle cx="8.8" cy="15.2" r="2.1" />
      <circle cx="12.5" cy="11.5" r="2.1" />
      <circle cx="16.2" cy="7.8" r="2.1" />
    </Icon>
  );
}

/** Bún / phở: a bowl with steam. */
export function IconNoodleBowl({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M3.5 11.5h17a8.5 8.5 0 0 1-8.5 8.5 8.5 8.5 0 0 1-8.5-8.5Z" />
      <path d="M2.5 11.5h19" />
      <path d="M9 3.2c-.9 1.2-.9 2.2 0 3.4" />
      <path d="M13.5 2.6c-1.1 1.4-1.1 2.7 0 4.1" />
    </Icon>
  );
}

/** Đồ uống: a cup with a straw. */
export function IconCup({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M5.5 5.5h13v2.2h-13Z" />
      <path d="M6.6 7.7h10.8l-1.2 11.1a2 2 0 0 1-2 1.7h-4.4a2 2 0 0 1-2-1.7Z" />
      <path d="M13.8 2.6 12.6 5.5" />
      <path d="M7.5 12.5h9" />
    </Icon>
  );
}

/** Combo: a bento box. */
export function IconBox({ size }: IconProps) {
  return (
    <Icon size={size}>
      <rect x="3" y="6.5" width="18" height="13.5" rx="2.4" />
      <path d="M3 11.2h18" />
      <path d="M12 11.2V20" />
      <path d="M8.5 6.5 10 3.5h4l1.5 3" />
    </Icon>
  );
}

/** Ăn vặt: a dumpling. */
export function IconDumpling({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M3.8 16.5a8.2 8.2 0 0 1 16.4 0Z" />
      <path d="M2.6 16.5h18.8" />
      <path d="M8 16.5v-3.1" />
      <path d="M12 16.5v-3.9" />
      <path d="M16 16.5v-3.1" />
    </Icon>
  );
}

/** Ưu đãi: a voucher. */
export function IconTicket({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M3 9A1.8 1.8 0 0 1 4.8 7.2h14.4A1.8 1.8 0 0 1 21 9v1.4a2 2 0 0 0 0 3.2V15a1.8 1.8 0 0 1-1.8 1.8H4.8A1.8 1.8 0 0 1 3 15v-1.4a2 2 0 0 0 0-3.2Z" />
      <path d="M14.5 7.2v9.6" strokeDasharray="2 2.2" />
    </Icon>
  );
}

/** Fallback for a real category this icon set has no dedicated glyph for. */
export function IconDish({ size }: IconProps) {
  return (
    <Icon size={size}>
      <circle cx="12" cy="12" r="8.4" />
      <circle cx="12" cy="12" r="3.2" />
    </Icon>
  );
}

/**
 * Renders the tab icon for a real category, keyed by its slug rather than its
 * (editable, Vietnamese) name. Falls back to a plain dish for a category this
 * storefront has no dedicated glyph for yet, so every tab still reads as food.
 */
export function CategoryIcon({ slug, size }: { readonly slug: string } & IconProps) {
  switch (slug) {
    case 'banh-mi':
      return <IconBaguette size={size} />;
    case 'xoi':
      return <IconXoi size={size} />;
    case 'mon-chinh':
      return <IconNoodleBowl size={size} />;
    case 'do-uong':
      return <IconCup size={size} />;
    case 'cha-dac-san':
      return <IconSkewer size={size} />;
    case 'combo':
      return <IconBox size={size} />;
    case 'an-vat':
      return <IconDumpling size={size} />;
    default:
      return <IconDish size={size} />;
  }
}

// ------------------------------------------------------------------ sections

export function IconFlame({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M12 2.6c3.4 3 5.4 5.8 5.4 9.1a5.4 5.4 0 1 1-10.8 0c0-2.2 1-4 2.5-5.3 0 1.9.9 3 2 3 1.3 0 2-1.2 2-2.8 0-1.4-.4-2.7-1.1-4Z" />
    </Icon>
  );
}

export function IconStar({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="m12 3.3 2.6 5.3 5.8.85-4.2 4.1 1 5.8-5.2-2.75L6.8 19.4l1-5.8-4.2-4.1 5.8-.85Z" />
    </Icon>
  );
}

// ------------------------------------------------------ features and panels

/** Giao nhanh. */
export function IconScooter({ size }: IconProps) {
  return (
    <Icon size={size}>
      <circle cx="5.6" cy="16.6" r="3.2" />
      <circle cx="18" cy="16.6" r="3.2" />
      <path d="M8.8 16.6h6" />
      <path d="M14.8 16.6 16.3 9.4h-3.1" />
      <path d="M13.4 5.2h2.7l1.9 11.4" />
    </Icon>
  );
}

/** Nguyên liệu tươi. */
export function IconLeaf({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M20 4c0 8.2-5.2 12.2-10.6 12.2A4.6 4.6 0 0 1 4.8 11.6C4.8 6.5 11 4 20 4Z" />
      <path d="M4 20c2.9-6 7.4-9.6 12-11.2" />
    </Icon>
  );
}

/** An toàn vệ sinh. */
export function IconShield({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M12 3 19.5 6v5.6c0 4.6-3.1 8-7.5 9.4-4.4-1.4-7.5-4.8-7.5-9.4V6Z" />
      <path d="m9 12.2 2.2 2.2 4.3-4.4" />
    </Icon>
  );
}

export function IconGift({ size }: IconProps) {
  return (
    <Icon size={size}>
      <rect x="3.2" y="9.2" width="17.6" height="10.8" rx="1.8" />
      <path d="M3.2 13.6h17.6" />
      <path d="M12 9.2V20" />
      <path d="M12 9.2S10.7 5.6 8.7 5.6a2.1 2.1 0 1 0 0 3.6Z" />
      <path d="M12 9.2s1.3-3.6 3.3-3.6a2.1 2.1 0 1 1 0 3.6Z" />
    </Icon>
  );
}

// ------------------------------------------------------------------- chrome

export function IconCart({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M2.8 4h2.4l2.4 11.2a1.8 1.8 0 0 0 1.8 1.4h8.1a1.8 1.8 0 0 0 1.8-1.4L21.2 8H6" />
      <circle cx="9.8" cy="20" r="1.3" />
      <circle cx="17.6" cy="20" r="1.3" />
    </Icon>
  );
}

/** Empty cart. */
export function IconBag({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M5.4 7.8h13.2L19.6 20H4.4Z" />
      <path d="M8.6 7.8V6a3.4 3.4 0 0 1 6.8 0v1.8" />
    </Icon>
  );
}

export function IconSearch({ size }: IconProps) {
  return (
    <Icon size={size}>
      <circle cx="11" cy="11" r="6.4" />
      <path d="m15.8 15.8 4.4 4.4" />
    </Icon>
  );
}

export function IconUser({ size }: IconProps) {
  return (
    <Icon size={size}>
      <circle cx="12" cy="8" r="3.8" />
      <path d="M4.8 20a7.2 7.2 0 0 1 14.4 0" />
    </Icon>
  );
}

export function IconHome({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M3.4 10.6 12 3.4l8.6 7.2" />
      <path d="M5.6 9.6V20h12.8V9.6" />
    </Icon>
  );
}

/** Thực đơn. */
export function IconMenuList({ size }: IconProps) {
  return (
    <Icon size={size}>
      <circle cx="5" cy="7" r="1.1" />
      <circle cx="5" cy="12" r="1.1" />
      <circle cx="5" cy="17" r="1.1" />
      <path d="M9.5 7h10" />
      <path d="M9.5 12h10" />
      <path d="M9.5 17h10" />
    </Icon>
  );
}

/** Sign out. */
export function IconLogout({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M9.5 4.2H6.2a2 2 0 0 0-2 2v11.6a2 2 0 0 0 2 2h3.3" />
      <path d="M16.3 16.3 21 11.6l-4.7-4.7" />
      <path d="M21 11.6H9.6" />
    </Icon>
  );
}

/** Order accepted. */
export function IconCheckCircle({ size }: IconProps) {
  return (
    <Icon size={size}>
      <circle cx="12" cy="12" r="9" />
      <path d="m8 12.3 2.6 2.6 5.4-5.4" />
    </Icon>
  );
}

/** Copy to clipboard - the order code, account number, amount, etc. */
export function IconCopy({ size }: IconProps) {
  return (
    <Icon size={size}>
      <rect x="9" y="9" width="11" height="11" rx="2" />
      <path d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1" />
    </Icon>
  );
}

/** A forward affordance - "Xem chi tiết", "Thanh toán ngay". */
export function IconChevronRight({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="m9 18 6-6-6-6" />
    </Icon>
  );
}

/** "Đơn hàng của tôi": an order awaiting confirmation. */
export function IconClock({ size }: IconProps) {
  return (
    <Icon size={size}>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7v5l3.2 2" />
    </Icon>
  );
}

/** A cancelled order. */
export function IconXCircle({ size }: IconProps) {
  return (
    <Icon size={size}>
      <circle cx="12" cy="12" r="9" />
      <path d="m9.2 9.2 5.6 5.6M14.8 9.2l-5.6 5.6" />
    </Icon>
  );
}

/** Awaiting payment - the status tile for an unpaid, unconfirmed order. */
export function IconWallet({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h11A2.5 2.5 0 0 1 20 7.5v9a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 16.5v-9Z" />
      <path d="M14 12h4v3h-4a1.5 1.5 0 0 1 0-3Z" />
    </Icon>
  );
}

/** A completed order - the "Hoàn thành" tab. */
export function IconMedal({ size }: IconProps) {
  return (
    <Icon size={size}>
      <circle cx="12" cy="9" r="5" />
      <path d="m9 13.3-1.5 6.4 4.5-2.4 4.5 2.4-1.5-6.4" />
    </Icon>
  );
}

/** The date filter. */
export function IconCalendar({ size }: IconProps) {
  return (
    <Icon size={size}>
      <rect x="3.5" y="5" width="17" height="15" rx="2" />
      <path d="M3.5 9.5h17M8 3v4M16 3v4" />
    </Icon>
  );
}

/** The sort filter. */
export function IconSort({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M7 4v16M4 7l3-3 3 3M17 20V4M14 17l3 3 3-3" />
    </Icon>
  );
}

/** "Đặt lại" - reorder a past order's items. */
export function IconRefresh({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M4 12a8 8 0 0 1 14-5.2M20 12a8 8 0 0 1-14 5.2" />
      <path d="M17.5 3.5v3.5H14M6.5 20.5V17H10" />
    </Icon>
  );
}

/** Delivery address book. */
export function IconMapPin({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M12 21s-7-6.1-7-11.5a7 7 0 1 1 14 0C19 14.9 12 21 12 21Z" />
      <circle cx="12" cy="9.5" r="2.3" />
    </Icon>
  );
}

/** "Mã giảm giá của tôi" - a coupon, distinct from the ticket icon used for promotions. */
export function IconTag({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M20 11.4 12.6 4H5a1 1 0 0 0-1 1v7.6a1 1 0 0 0 .3.7l8.4 8.4a1 1 0 0 0 1.4 0l6.3-6.3a1 1 0 0 0 0-1.4Z" />
      <circle cx="8.3" cy="8.3" r="1.3" />
    </Icon>
  );
}

/** "Thanh toán ngay" - the VietQR payment action. */
export function IconQrCode({ size }: IconProps) {
  return (
    <Icon size={size}>
      <rect x="3.5" y="3.5" width="6.5" height="6.5" rx="1" />
      <rect x="14" y="3.5" width="6.5" height="6.5" rx="1" />
      <rect x="3.5" y="14" width="6.5" height="6.5" rx="1" />
      <path d="M14 14h3v3h-3zM19 14h1.5M14 19h1.5M19 19h1.5" />
    </Icon>
  );
}

// ------------------------------------------------------------ food thumbs

/**
 * Stand-ins for product photography, one per dish family.
 *
 * The same line set the admin dashboard uses for the same purpose - a product
 * created there with one of these names looks the same here.
 */
export type FoodIconName = 'banh-mi' | 'xoi' | 'pho' | 'drink' | 'combo' | 'gift';

function IconBanhMi({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M3.5 13.5c0-3.6 3.8-6.5 8.5-6.5s8.5 2.9 8.5 6.5" />
      <path d="M3.5 13.5c0 2 1.6 3.5 3.5 3.5h10c1.9 0 3.5-1.5 3.5-3.5" />
      <path d="M3.5 13.5h17" />
      <path d="M8.2 9.6 9.6 12" />
      <path d="M13 9.1 14.4 11.5" />
    </Icon>
  );
}

/** Xôi: also used as the Xôi category tab's icon. */
export function IconXoi({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M4.5 12.5h15a7.5 7.5 0 0 1-15 0Z" />
      <path d="M3.5 12.5h17" />
      <path d="M8.4 9.4c.9-1 2.1-1.6 3.6-1.6s2.7.6 3.6 1.6" />
      <path d="M11 5.4c.6-.5 1.4-.5 2 0" />
    </Icon>
  );
}

function IconPho({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M3.5 11.5h17a8.5 8.5 0 0 1-8.5 8.5 8.5 8.5 0 0 1-8.5-8.5Z" />
      <path d="M2.5 11.5h19" />
      <path d="M9 3.2c-.9 1.2-.9 2.2 0 3.4" />
      <path d="M13.5 2.6c-1.1 1.4-1.1 2.7 0 4.1" />
    </Icon>
  );
}

function IconDrinkThumb({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M5.5 5.5h13v2.2h-13Z" />
      <path d="M6.6 7.7h10.8l-1.2 11.1a2 2 0 0 1-2 1.7h-4.4a2 2 0 0 1-2-1.7Z" />
      <path d="M13.8 2.6 12.6 5.5" />
      <path d="M7.5 12.5h9" />
    </Icon>
  );
}

function IconComboThumb({ size }: IconProps) {
  return (
    <Icon size={size}>
      <rect x="3" y="6.5" width="18" height="13.5" rx="2.4" />
      <path d="M3 11.2h18" />
      <path d="M12 11.2V20" />
      <path d="M8.5 6.5 10 3.5h4l1.5 3" />
    </Icon>
  );
}

/**
 * Renders the thumbnail icon a dish is tagged with, falling back to the gift
 * box for anything the server sends that is not one of the known names - a
 * category this storefront does not know about yet must still show something.
 */
export function FoodIcon({ name, size }: { readonly name: string | null } & IconProps) {
  switch (name) {
    case 'banh-mi':
      return <IconBanhMi size={size} />;
    case 'xoi':
      return <IconXoi size={size} />;
    case 'pho':
      return <IconPho size={size} />;
    case 'drink':
      return <IconDrinkThumb size={size} />;
    case 'combo':
      return <IconComboThumb size={size} />;
    default:
      return <IconGift size={size} />;
  }
}
