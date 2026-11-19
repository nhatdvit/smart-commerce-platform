/**
 * Demo data for the parts of the dashboard that have no API yet.
 *
 * The menu screen no longer reads from here: products and menus are real, and
 * live in `@/lib/menu-api`. Orders, coupons, rewards and customers are still
 * illustrative sample data held in memory, because the Order, Promotion and
 * Loyalty endpoints are designed but not built. Replace each the same way as
 * its module lands - delete its block here and point the provider at the API.
 */

import type { FoodIconName } from '@/components/admin/icons';

export type OrderStatus = 'waiting' | 'preparing' | 'ready' | 'done' | 'cancelled';

export interface DemoOrder {
  readonly id: string;
  readonly name: string;
  readonly items: string;
  /** Integer đồng. */
  readonly total: number;
  readonly status: OrderStatus;
  readonly payment: string;
  readonly time: string;
}

export interface DemoCoupon {
  readonly code: string;
  readonly desc: string;
  readonly used: number;
  readonly limit: number;
}

export interface DemoReward {
  readonly name: string;
  readonly points: number;
  readonly icon: FoodIconName;
  readonly claimed: number;
}

export interface DemoCustomer {
  readonly name: string;
  readonly orders: number;
  /** Integer đồng. */
  readonly spent: number;
  readonly points: number;
  readonly tier: string;
}

export const ORDER_STATUS_LABELS: Readonly<Record<OrderStatus, string>> = {
  waiting: 'Chờ xác nhận',
  preparing: 'Đang chuẩn bị',
  ready: 'Sẵn sàng',
  done: 'Hoàn thành',
  cancelled: 'Đã hủy',
};

/**
 * The forward-only path a staff member can advance an order along in this
 * design. The real transition rules belong to the Order module's state machine
 * (CLAUDE.md section 6), which also owns cancellation and expiry.
 */
export const ORDER_STATUS_FLOW: readonly OrderStatus[] = ['waiting', 'preparing', 'ready', 'done'];

export function nextOrderStatus(status: OrderStatus): OrderStatus | undefined {
  return ORDER_STATUS_FLOW[ORDER_STATUS_FLOW.indexOf(status) + 1];
}

export const INITIAL_ORDERS: readonly DemoOrder[] = [
  {
    id: 'PQ-1028',
    name: 'Nguyễn Minh Anh',
    items: '2 Bánh mì pate trứng, 1 Cà phê sữa',
    total: 58000,
    status: 'waiting',
    payment: 'Chuyển khoản',
    time: '07:15',
  },
  {
    id: 'PQ-1027',
    name: 'Trần Quốc Huy',
    items: '1 Xôi gà, 1 Trà tắc',
    total: 40000,
    status: 'preparing',
    payment: 'Tiền mặt',
    time: '07:30',
  },
  {
    id: 'PQ-1026',
    name: 'Lê Thảo Vy',
    items: '2 Bánh mì xá xíu',
    total: 56000,
    status: 'ready',
    payment: 'Chuyển khoản',
    time: '07:45',
  },
  {
    id: 'PQ-1025',
    name: 'Phạm Hoàng Long',
    items: '1 Phở bò, 1 Cà phê đen',
    total: 55000,
    status: 'done',
    payment: 'Tiền mặt',
    time: '07:00',
  },
  {
    id: 'PQ-1024',
    name: 'Đỗ Thanh Hà',
    items: '1 Bánh mì gà nướng',
    total: 25000,
    status: 'waiting',
    payment: 'Tiền mặt',
    time: '08:00',
  },
  {
    id: 'PQ-1023',
    name: 'Vũ Hồng Nhung',
    items: '2 Xôi thịt, 2 Trà đào',
    total: 92000,
    status: 'waiting',
    payment: 'Chuyển khoản',
    time: '08:15',
  },
];

export const INITIAL_COUPONS: readonly DemoCoupon[] = [
  { code: 'GIAM5K', desc: 'Giảm 5.000đ · Đơn từ 50.000đ', used: 18, limit: 100 },
  { code: 'SANG10', desc: 'Giảm 10% · Đơn từ 100.000đ', used: 9, limit: 50 },
];

export const INITIAL_REWARDS: readonly DemoReward[] = [
  { name: 'Cà phê sữa đá', points: 150, icon: 'drink', claimed: 12 },
  { name: 'Bánh mì pate trứng', points: 200, icon: 'banh-mi', claimed: 7 },
  { name: 'Xôi gà xé', points: 250, icon: 'xoi', claimed: 4 },
];

export const DEMO_CUSTOMERS: readonly DemoCustomer[] = [
  { name: 'Nguyễn Minh Anh', orders: 12, spent: 385000, points: 185, tier: 'Thân thiết' },
  { name: 'Trần Quốc Huy', orders: 8, spent: 245000, points: 96, tier: 'Thành viên' },
  { name: 'Lê Thảo Vy', orders: 16, spent: 592000, points: 240, tier: 'Thân thiết' },
  { name: 'Phạm Hoàng Long', orders: 4, spent: 125000, points: 38, tier: 'Thành viên' },
];

/** Bar heights in percent and the weekday labels of the dashboard revenue chart. */
export const REVENUE_CHART: readonly number[] = [46, 66, 58, 82, 64, 93, 75];
export const WEEKDAY_LABELS: readonly string[] = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];
/** Units sold today, shown against the first four products on the dashboard. */
export const BESTSELLER_COUNTS: readonly number[] = [38, 32, 25, 21];
