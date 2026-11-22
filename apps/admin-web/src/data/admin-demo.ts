/**
 * Demo data for the parts of the dashboard that have no API yet.
 *
 * Four screens no longer read from here. The menu is real - products, prices
 * and portions live in `@/lib/menu-api` - and so are orders
 * (`@/lib/orders-api`), discount codes (`@/lib/coupons-api`) and loyalty
 * (`@/lib/loyalty-api`). Customers is still illustrative sample data held in
 * memory, because the Customer endpoint is designed but not built. Replace it
 * the same way as its module lands: delete its block here and point the
 * provider at the API.
 *
 * The dashboard's revenue chart and bestseller counts are still illustrative
 * too, and the screen says so where it shows them.
 */

export interface DemoCustomer {
  readonly name: string;
  readonly orders: number;
  /** Integer đồng. */
  readonly spent: number;
  readonly points: number;
  readonly tier: string;
}

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
