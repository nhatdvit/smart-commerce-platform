/**
 * Admin icon set.
 *
 * The delivered design used box-drawing and miscellaneous-symbol characters as
 * icons (▦ ▤ ▧ ▥ ▣ ◇ ☆ ♧ ◈ ⚙ ◷ ◉ ↗ ✂ ⌄ ☰) plus food emoji for product
 * thumbnails. None of them is in Be Vietnam Pro, so every platform substitutes
 * its own font: they arrive at different sizes and weights, several are pure
 * geometry that means nothing to a reader, and the emoji render as colour clip
 * art rather than as interface.
 *
 * This is the same line set the storefront uses - a 24x24 grid, stroked in
 * `currentColor`, sized by the component rather than by `font-size` - so the
 * two apps share one visual language.
 */
import type { ReactNode } from 'react';

interface IconProps {
  /** Rendered width and height in px. */
  readonly size?: number;
}

function Icon({ size = 20, children }: IconProps & { readonly children: ReactNode }) {
  return (
    <svg
      className="admin-icon"
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

// -------------------------------------------------------------- navigation

export function IconDashboard({ size }: IconProps) {
  return (
    <Icon size={size}>
      <rect x="3.5" y="3.5" width="7" height="7" rx="1.8" />
      <rect x="13.5" y="3.5" width="7" height="7" rx="1.8" />
      <rect x="3.5" y="13.5" width="7" height="7" rx="1.8" />
      <rect x="13.5" y="13.5" width="7" height="7" rx="1.8" />
    </Icon>
  );
}

/** Đơn hàng: a receipt. */
export function IconReceipt({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M5 3.2h14v17.6l-2.3-1.6-2.4 1.6-2.3-1.6-2.3 1.6L7.3 19 5 20.8Z" />
      <path d="M8.6 8.2h6.8" />
      <path d="M8.6 12.2h6.8" />
    </Icon>
  );
}

/** Thực đơn. */
export function IconMenuBook({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M3.5 5a2 2 0 0 1 2-2H11v17H5.5a2 2 0 0 0-2 2Z" />
      <path d="M20.5 5a2 2 0 0 0-2-2H13v17h5.5a2 2 0 0 1 2 2Z" />
    </Icon>
  );
}

/** Số suất và tồn kho. */
export function IconBoxes({ size }: IconProps) {
  return (
    <Icon size={size}>
      <rect x="3" y="12.5" width="8" height="8" rx="1.4" />
      <rect x="13" y="12.5" width="8" height="8" rx="1.4" />
      <rect x="8" y="3.5" width="8" height="8" rx="1.4" />
    </Icon>
  );
}

/** Thanh toán. */
export function IconWallet({ size }: IconProps) {
  return (
    <Icon size={size}>
      <rect x="3" y="5.5" width="18" height="13" rx="2.4" />
      <path d="M3 10h18" />
      <circle cx="17" cy="14.5" r="1.2" />
    </Icon>
  );
}

/** Mã ưu đãi. */
export function IconTicket({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M3 9A1.8 1.8 0 0 1 4.8 7.2h14.4A1.8 1.8 0 0 1 21 9v1.4a2 2 0 0 0 0 3.2V15a1.8 1.8 0 0 1-1.8 1.8H4.8A1.8 1.8 0 0 1 3 15v-1.4a2 2 0 0 0 0-3.2Z" />
      <path d="M14.5 7.2v9.6" strokeDasharray="2 2.2" />
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

/** Khách hàng. */
export function IconUsers({ size }: IconProps) {
  return (
    <Icon size={size}>
      <circle cx="9.5" cy="8" r="3.4" />
      <path d="M3.4 19.4a6.1 6.1 0 0 1 12.2 0" />
      <path d="M16.2 5.2a3.4 3.4 0 0 1 0 6.3" />
      <path d="M17.6 14.2a6.1 6.1 0 0 1 3 5.2" />
    </Icon>
  );
}

/** Giao diện cửa hàng. */
export function IconStorefront({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M4 9.5V20h16V9.5" />
      <path d="M3 9.5 5 4h14l2 5.5a2.8 2.8 0 0 1-5.4 0 2.8 2.8 0 0 1-5.2 0 2.8 2.8 0 0 1-5.4 0Z" />
      <path d="M9.8 20v-5.4h4.4V20" />
    </Icon>
  );
}

export function IconSettings({ size }: IconProps) {
  return (
    <Icon size={size}>
      <circle cx="12" cy="12" r="3.1" />
      <path d="M19.4 14.4a1.6 1.6 0 0 0 .32 1.76l.06.06a1.9 1.9 0 1 1-2.7 2.7l-.05-.06a1.6 1.6 0 0 0-1.77-.32 1.6 1.6 0 0 0-.97 1.46V20a1.9 1.9 0 1 1-3.8 0v-.1a1.6 1.6 0 0 0-1.04-1.46 1.6 1.6 0 0 0-1.76.33l-.06.05a1.9 1.9 0 1 1-2.7-2.7l.06-.05a1.6 1.6 0 0 0 .32-1.77 1.6 1.6 0 0 0-1.46-.97H4a1.9 1.9 0 1 1 0-3.8h.1a1.6 1.6 0 0 0 1.46-1.04 1.6 1.6 0 0 0-.33-1.76l-.05-.06a1.9 1.9 0 1 1 2.7-2.7l.05.06a1.6 1.6 0 0 0 1.77.32H9.8a1.6 1.6 0 0 0 .97-1.46V4a1.9 1.9 0 1 1 3.8 0v.1a1.6 1.6 0 0 0 .97 1.46 1.6 1.6 0 0 0 1.77-.32l.05-.06a1.9 1.9 0 1 1 2.7 2.7l-.06.05a1.6 1.6 0 0 0-.32 1.77V9.8a1.6 1.6 0 0 0 1.46.97H20a1.9 1.9 0 1 1 0 3.8h-.1a1.6 1.6 0 0 0-1.46.97Z" />
    </Icon>
  );
}

// ------------------------------------------------------------------ chrome

export function IconHamburger({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M4 7h16" />
      <path d="M4 12h16" />
      <path d="M4 17h16" />
    </Icon>
  );
}

export function IconClose({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="m6 6 12 12" />
      <path d="m18 6-12 12" />
    </Icon>
  );
}

export function IconBell({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M18 8.8a6 6 0 1 0-12 0c0 5.4-2 7-2 7h16s-2-1.6-2-7Z" />
      <path d="M13.7 19.4a2 2 0 0 1-3.4 0" />
    </Icon>
  );
}

export function IconChevronDown({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="m6.5 9.5 5.5 5.5 5.5-5.5" />
    </Icon>
  );
}

export function IconHelp({ size }: IconProps) {
  return (
    <Icon size={size}>
      <circle cx="12" cy="12" r="9" />
      <path d="M9.6 9.3a2.5 2.5 0 1 1 3.3 2.4c-.6.2-.9.8-.9 1.4v.4" />
      <path d="M12 17h.01" />
    </Icon>
  );
}

// ----------------------------------------------------------------- metrics

export function IconTrendUp({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="m3.5 16.5 5.5-5.5 3.5 3.5 7-7" />
      <path d="M15 7.5h5.5V13" />
    </Icon>
  );
}

export function IconClock({ size }: IconProps) {
  return (
    <Icon size={size}>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7v5.3l3.2 1.9" />
    </Icon>
  );
}

export function IconHeart({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M12 20.8 10.6 19.5C5.6 15 2.4 12 2.4 8.4A5.4 5.4 0 0 1 12 5.1a5.4 5.4 0 0 1 9.6 3.3c0 3.6-3.2 6.6-8.2 11.1Z" />
    </Icon>
  );
}

export function IconCheckCircle({ size }: IconProps) {
  return (
    <Icon size={size}>
      <circle cx="12" cy="12" r="9" />
      <path d="m8 12.3 2.6 2.6 5.4-5.4" />
    </Icon>
  );
}

export function IconAlert({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M10.3 3.9 2.6 17.1A2 2 0 0 0 4.3 20h15.4a2 2 0 0 0 1.7-2.9L13.7 3.9a2 2 0 0 0-3.4 0Z" />
      <path d="M12 9.5v4" />
      <path d="M12 16.8h.01" />
    </Icon>
  );
}

export function IconXCircle({ size }: IconProps) {
  return (
    <Icon size={size}>
      <circle cx="12" cy="12" r="9" />
      <path d="m9.2 9.2 5.6 5.6" />
      <path d="m14.8 9.2-5.6 5.6" />
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

// ----------------------------------------------------------- page actions

export function IconDownload({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M12 3.5v11" />
      <path d="m7.5 10.2 4.5 4.3 4.5-4.3" />
      <path d="M4 17.5v1.4a1.6 1.6 0 0 0 1.6 1.6h12.8a1.6 1.6 0 0 0 1.6-1.6v-1.4" />
    </Icon>
  );
}

export function IconPlus({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M12 5v14" />
      <path d="M5 12h14" />
    </Icon>
  );
}

export function IconCalendar({ size }: IconProps) {
  return (
    <Icon size={size}>
      <rect x="3.2" y="5" width="17.6" height="15.8" rx="2.2" />
      <path d="M3.2 9.8h17.6" />
      <path d="M8 3.2v3.4" />
      <path d="M16 3.2v3.4" />
    </Icon>
  );
}

export function IconEye({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M2.2 12S5.8 5.6 12 5.6 21.8 12 21.8 12 18.2 18.4 12 18.4 2.2 12 2.2 12Z" />
      <circle cx="12" cy="12" r="3" />
    </Icon>
  );
}

export function IconScissors({ size }: IconProps) {
  return (
    <Icon size={size}>
      <circle cx="6" cy="6" r="2.6" />
      <circle cx="6" cy="18" r="2.6" />
      <path d="M8.2 7.8 20 19" />
      <path d="M8.2 16.2 20 5" />
    </Icon>
  );
}

export function IconCart({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M2.8 4h2.4l2.4 11.2a1.8 1.8 0 0 0 1.8 1.4h8.1a1.8 1.8 0 0 0 1.8-1.4L21.2 8H6" />
      <circle cx="9.8" cy="20" r="1.3" />
      <circle cx="17.6" cy="20" r="1.3" />
    </Icon>
  );
}

// ------------------------------------------------------------ food thumbs

/** Stand-ins for product photography, one per menu category. */
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

function IconXoi({ size }: IconProps) {
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

function IconDrink({ size }: IconProps) {
  return (
    <Icon size={size}>
      <path d="M5.5 5.5h13v2.2h-13Z" />
      <path d="M6.6 7.7h10.8l-1.2 11.1a2 2 0 0 1-2 1.7h-4.4a2 2 0 0 1-2-1.7Z" />
      <path d="M13.8 2.6 12.6 5.5" />
      <path d="M7.5 12.5h9" />
    </Icon>
  );
}

function IconCombo({ size }: IconProps) {
  return (
    <Icon size={size}>
      <rect x="3" y="6.5" width="18" height="13.5" rx="2.4" />
      <path d="M3 11.2h18" />
      <path d="M12 11.2V20" />
      <path d="M8.5 6.5 10 3.5h4l1.5 3" />
    </Icon>
  );
}

/** Renders the thumbnail icon a product or reward is tagged with. */
export function FoodIcon({ name, size = 25 }: { readonly name: FoodIconName } & IconProps) {
  switch (name) {
    case 'banh-mi':
      return <IconBanhMi size={size} />;
    case 'xoi':
      return <IconXoi size={size} />;
    case 'pho':
      return <IconPho size={size} />;
    case 'drink':
      return <IconDrink size={size} />;
    case 'combo':
      return <IconCombo size={size} />;
    case 'gift':
      return <IconGift size={size} />;
  }
}
