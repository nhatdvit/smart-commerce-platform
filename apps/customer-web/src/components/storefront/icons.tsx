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

/** Order accepted. */
export function IconCheckCircle({ size }: IconProps) {
  return (
    <Icon size={size}>
      <circle cx="12" cy="12" r="9" />
      <path d="m8 12.3 2.6 2.6 5.4-5.4" />
    </Icon>
  );
}
