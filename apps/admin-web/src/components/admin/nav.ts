import type { ComponentType } from 'react';

import {
  IconBoxes,
  IconDashboard,
  IconMenuBook,
  IconReceipt,
  IconSettings,
  IconStar,
  IconStorefront,
  IconTicket,
  IconUsers,
  IconWallet,
} from './icons';

/** The sidebar, grouped exactly as the design groups it. */
export interface NavItem {
  readonly href: string;
  readonly Icon: ComponentType<{ readonly size?: number }>;
  readonly label: string;
  /** Renders the pending-order count on the right of the row. */
  readonly badge?: 'pending';
}

export interface NavGroup {
  readonly title: string;
  readonly items: readonly NavItem[];
}

export const NAV_GROUPS: readonly NavGroup[] = [
  {
    title: 'TỔNG QUAN',
    items: [
      { href: '/', Icon: IconDashboard, label: 'Tổng quan' },
      { href: '/orders', Icon: IconReceipt, label: 'Đơn hàng', badge: 'pending' },
      { href: '/menu', Icon: IconMenuBook, label: 'Thực đơn & món ăn' },
      { href: '/inventory', Icon: IconBoxes, label: 'Số suất & tồn kho' },
    ],
  },
  {
    title: 'KINH DOANH',
    items: [
      { href: '/payments', Icon: IconWallet, label: 'Thanh toán' },
      { href: '/promotions', Icon: IconTicket, label: 'Mã ưu đãi' },
      { href: '/loyalty', Icon: IconStar, label: 'Tích điểm & đổi món' },
      { href: '/customers', Icon: IconUsers, label: 'Khách hàng' },
    ],
  },
  {
    title: 'HỆ THỐNG',
    items: [
      { href: '/storefront', Icon: IconStorefront, label: 'Giao diện cửa hàng' },
      { href: '/settings', Icon: IconSettings, label: 'Cài đặt' },
    ],
  },
];

const ALL_ITEMS = NAV_GROUPS.flatMap((group) => group.items);

/** Breadcrumb label for a pathname; falls back to the dashboard. */
export function navLabelFor(pathname: string): string {
  return ALL_ITEMS.find((item) => item.href === pathname)?.label ?? 'Tổng quan';
}
