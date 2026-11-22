/**
 * Demo data for the parts of the dashboard that have no API yet.
 *
 * The menu is real - products, prices and portions live in `@/lib/menu-api` -
 * and so are orders (`@/lib/orders-api`), discount codes (`@/lib/coupons-api`)
 * and customers (`@/lib/customers-api`). Rewards are still illustrative
 * sample data held in memory, because the Loyalty endpoints are designed but
 * not built. Replace it the same way as its module lands: delete its block
 * here and point the provider at the API.
 *
 * The dashboard's revenue chart and bestseller counts are still illustrative
 * too, and the screen says so where it shows them.
 */

import type { FoodIconName } from '@/components/admin/icons';

export interface DemoReward {
  readonly name: string;
  readonly points: number;
  readonly icon: FoodIconName;
  readonly claimed: number;
}

export const INITIAL_REWARDS: readonly DemoReward[] = [
  { name: 'Cà phê sữa đá', points: 150, icon: 'drink', claimed: 12 },
  { name: 'Bánh mì pate trứng', points: 200, icon: 'banh-mi', claimed: 7 },
  { name: 'Xôi gà xé', points: 250, icon: 'xoi', claimed: 4 },
];

/** Bar heights in percent and the weekday labels of the dashboard revenue chart. */
export const REVENUE_CHART: readonly number[] = [46, 66, 58, 82, 64, 93, 75];
export const WEEKDAY_LABELS: readonly string[] = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];
/** Units sold today, shown against the first four products on the dashboard. */
export const BESTSELLER_COUNTS: readonly number[] = [38, 32, 25, 21];
