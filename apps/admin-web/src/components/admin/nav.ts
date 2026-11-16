/** The sidebar, grouped exactly as the design groups it. */
export interface NavItem {
  readonly href: string;
  readonly icon: string;
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
      { href: '/', icon: '▦', label: 'Tổng quan' },
      { href: '/orders', icon: '▤', label: 'Đơn hàng', badge: 'pending' },
      { href: '/menu', icon: '▧', label: 'Thực đơn & món ăn' },
      { href: '/inventory', icon: '▥', label: 'Số suất & tồn kho' },
    ],
  },
  {
    title: 'KINH DOANH',
    items: [
      { href: '/payments', icon: '▣', label: 'Thanh toán' },
      { href: '/promotions', icon: '◇', label: 'Mã ưu đãi' },
      { href: '/loyalty', icon: '☆', label: 'Tích điểm & đổi món' },
      { href: '/customers', icon: '♧', label: 'Khách hàng' },
    ],
  },
  {
    title: 'HỆ THỐNG',
    items: [
      { href: '/storefront', icon: '◈', label: 'Giao diện cửa hàng' },
      { href: '/settings', icon: '⚙', label: 'Cài đặt' },
    ],
  },
];

const ALL_ITEMS = NAV_GROUPS.flatMap((group) => group.items);

/** Breadcrumb label for a pathname; falls back to the dashboard. */
export function navLabelFor(pathname: string): string {
  return ALL_ITEMS.find((item) => item.href === pathname)?.label ?? 'Tổng quan';
}
