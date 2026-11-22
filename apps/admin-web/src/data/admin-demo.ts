/**
 * Demo data for the parts of the dashboard that have no API yet.
 *
 * The menu, orders, discount codes, customers and loyalty are all real now:
 * products, prices and portions live in `@/lib/menu-api`, and so do orders
 * (`@/lib/orders-api`), discount codes (`@/lib/coupons-api`), customers
 * (`@/lib/customers-api`) and loyalty (`@/lib/loyalty-api`). Nothing in this
 * module is read by a screen any more.
 *
 * The dashboard's revenue chart and bestseller counts are still illustrative,
 * and the screen says so where it shows them.
 */

/** Bar heights in percent and the weekday labels of the dashboard revenue chart. */
export const REVENUE_CHART: readonly number[] = [46, 66, 58, 82, 64, 93, 75];
export const WEEKDAY_LABELS: readonly string[] = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];
/** Units sold today, shown against the first four products on the dashboard. */
export const BESTSELLER_COUNTS: readonly number[] = [38, 32, 25, 21];
