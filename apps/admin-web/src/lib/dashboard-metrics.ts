/**
 * Everything the dashboard shows, derived from orders the API already serves.
 *
 * Pure functions on purpose: no fetching, no React, no clock of its own. The
 * whole screen's arithmetic is therefore readable in one file and testable
 * without a browser - and when `GET /admin/reports/overview` exists, this is
 * the single module that gets replaced, not thirteen components
 * (docs/specifications/admin-dashboard-redesign.md section 2.6).
 *
 * Two rules decide every number below, both from CLAUDE.md section 6:
 *
 *   - An order's status and its payment's status are separate state machines.
 *     Revenue is counted from orders; money received is counted from payments.
 *     Neither is inferred from the other.
 *   - A customer saying "I have transferred" (`PROCESSING`) is a notification,
 *     never proof of funds. It is reported as *waiting to be reconciled* and
 *     never added to what the shop has taken.
 */

import type { Bucket } from './dashboard-range';
import {
  ORDER_STATUS_LABELS,
  statusTone,
  type AdminOrder,
  type OrderStatus,
  type PaymentMethod,
} from './orders-api';

/**
 * The orders that count as sales.
 *
 * `PENDING_PAYMENT` is absent because such an order has not been placed yet -
 * it has no `placed_at` at all, so the API's own date window already excludes
 * it. `CANCELLED` and `EXPIRED` are absent because they are not sales.
 */
export const SALES_STATUSES: readonly OrderStatus[] = [
  'PLACED',
  'CONFIRMED',
  'PREPARING',
  'READY',
  'COMPLETED',
];

/** Statuses that no longer owe the shop anything, so they cannot be "chờ thu". */
const CLOSED_STATUSES: readonly OrderStatus[] = ['CANCELLED', 'EXPIRED'];

export interface SeriesPoint extends Bucket {
  readonly revenue: number;
  readonly orders: number;
}

export interface StatusSlice {
  /** The same five tones the status badges use, so colours agree everywhere. */
  readonly tone: string;
  readonly label: string;
  readonly count: number;
  /** Which server statuses this slice gathers, for the tooltip and the link. */
  readonly statuses: readonly OrderStatus[];
}

export interface PaymentTotals {
  /** Confirmed by a named staff member against the real money. */
  readonly collected: number;
  readonly collectedCash: number;
  readonly collectedTransfer: number;
  readonly collectedCount: number;
  /** Still owed on orders that are still alive. */
  readonly outstanding: number;
  readonly outstandingCount: number;
  /** The part of `outstanding` a customer has already reported transferring. */
  readonly awaitingReconciliation: number;
  readonly awaitingReconciliationCount: number;
  /** Orders whose payment is in a refund state. Count only - see §5 of the spec. */
  readonly refundingCount: number;
}

export interface BestSeller {
  readonly name: string;
  readonly quantity: number;
  /** Share of the best seller's quantity, for the bar. 0-1. */
  readonly share: number;
}

export interface DashboardMetrics {
  /** Sum of order totals for `SALES_STATUSES` in the window. */
  readonly revenue: number;
  readonly orderCount: number;
  /** Null rather than zero when there is nothing to average. */
  readonly averageOrderValue: number | null;
  /** Every order in the window, cancellations included. */
  readonly receivedCount: number;
  readonly series: readonly SeriesPoint[];
  readonly statusMix: readonly StatusSlice[];
  readonly payment: PaymentTotals;
  readonly bestsellers: readonly BestSeller[];
}

const TONE_ORDER: readonly string[] = ['waiting', 'preparing', 'ready', 'done', 'cancelled'];

const TONE_LABELS: Readonly<Record<string, string>> = {
  waiting: 'Chờ xác nhận',
  preparing: 'Đang chuẩn bị',
  ready: 'Sẵn sàng',
  done: 'Hoàn thành',
  cancelled: 'Đã hủy / hết hạn',
};

/**
 * When this order happened, for bucketing.
 *
 * `placed_at` is what the API filtered the window on, so it is what the chart
 * must bucket by; `created_at` only stands in for the rare row that has none.
 */
function instantOf(order: AdminOrder): number {
  const iso = order.placedAt ?? order.createdAt;

  if (iso === null) {
    return Number.NaN;
  }

  const at = new Date(iso).getTime();

  return Number.isNaN(at) ? Number.NaN : at;
}

function isSale(order: AdminOrder): boolean {
  return SALES_STATUSES.includes(order.status);
}

function isClosed(order: AdminOrder): boolean {
  return CLOSED_STATUSES.includes(order.status);
}

function sum(orders: readonly AdminOrder[]): number {
  return orders.reduce((total, order) => total + order.total, 0);
}

function paymentTotals(orders: readonly AdminOrder[]): PaymentTotals {
  const paid = orders.filter((order) => order.paymentStatus === 'PAID');
  const byMethod = (method: PaymentMethod): number =>
    sum(paid.filter((order) => order.paymentMethod === method));

  // Money still to collect, on orders that are still alive. A cancelled order
  // nobody paid for is not a receivable (spec section 7H), and an order with
  // no payment row at all is an anomaly the orders table reports as one rather
  // than something to quietly add up here.
  const owing = orders.filter(
    (order) =>
      !isClosed(order) &&
      (order.paymentStatus === 'PENDING' || order.paymentStatus === 'PROCESSING'),
  );

  const reported = owing.filter((order) => order.paymentStatus === 'PROCESSING');

  return {
    collected: sum(paid),
    collectedCash: byMethod('CASH'),
    collectedTransfer: byMethod('BANK_TRANSFER'),
    collectedCount: paid.length,
    outstanding: sum(owing),
    outstandingCount: owing.length,
    awaitingReconciliation: sum(reported),
    awaitingReconciliationCount: reported.length,
    refundingCount: orders.filter(
      (order) => order.paymentStatus === 'PARTIALLY_REFUNDED' || order.paymentStatus === 'REFUNDED',
    ).length,
  };
}

function statusMix(orders: readonly AdminOrder[]): readonly StatusSlice[] {
  const counts = new Map<string, { count: number; statuses: Set<OrderStatus> }>();

  for (const order of orders) {
    const tone = statusTone(order.status);
    const entry = counts.get(tone) ?? { count: 0, statuses: new Set<OrderStatus>() };

    entry.count += 1;
    entry.statuses.add(order.status);
    counts.set(tone, entry);
  }

  return TONE_ORDER.map((tone) => {
    const entry = counts.get(tone);

    return {
      tone,
      label: TONE_LABELS[tone] ?? tone,
      count: entry?.count ?? 0,
      statuses: [...(entry?.statuses ?? [])],
    };
  }).filter((slice) => slice.count > 0);
}

/**
 * Units sold per dish.
 *
 * Grouped by product name because that is the only identity the order summary
 * carries - its line previews have no product id (spec section 5, backend
 * request 3). Two dishes genuinely sharing a name would merge here; the fix is
 * the id, not a guess.
 *
 * Revenue per dish is deliberately absent: the summary has no unit price, and
 * multiplying the menu's current price by yesterday's quantity would invent a
 * figure the order lines already contradict.
 */
function bestsellers(orders: readonly AdminOrder[], limit: number): readonly BestSeller[] {
  const quantities = new Map<string, number>();

  for (const order of orders) {
    for (const line of order.items) {
      quantities.set(line.name, (quantities.get(line.name) ?? 0) + line.quantity);
    }
  }

  const ranked = [...quantities.entries()]
    .map(([name, quantity]) => ({ name, quantity }))
    .sort((a, b) => b.quantity - a.quantity || a.name.localeCompare(b.name, 'vi'))
    .slice(0, limit);

  const top = ranked[0]?.quantity ?? 0;

  return ranked.map((entry) => ({
    ...entry,
    share: top === 0 ? 0 : entry.quantity / top,
  }));
}

function series(orders: readonly AdminOrder[], buckets: readonly Bucket[]): readonly SeriesPoint[] {
  const points = buckets.map((bucket) => ({ ...bucket, revenue: 0, orders: 0 }));

  if (points.length === 0) {
    return points;
  }

  for (const order of orders) {
    const at = instantOf(order);

    if (Number.isNaN(at)) {
      continue;
    }

    // Linear scan rather than a binary search: thirty buckets at most, and an
    // index built per call would cost more than it saves.
    const point = points.find((candidate) => at >= candidate.fromMs && at < candidate.toMs);

    if (point !== undefined) {
      point.revenue += order.total;
      point.orders += 1;
    }
  }

  return points;
}

export function computeMetrics(
  orders: readonly AdminOrder[],
  buckets: readonly Bucket[],
  bestsellerLimit = 5,
): DashboardMetrics {
  const sales = orders.filter(isSale);
  const revenue = sum(sales);

  return {
    revenue,
    orderCount: sales.length,
    averageOrderValue: sales.length === 0 ? null : Math.round(revenue / sales.length),
    receivedCount: orders.length,
    series: series(sales, buckets),
    statusMix: statusMix(orders),
    payment: paymentTotals(orders),
    bestsellers: bestsellers(sales, bestsellerLimit),
  };
}

/**
 * Change against the comparison window, as a fraction.
 *
 * Null when there is nothing to compare against: a shop's first day would
 * otherwise read "+100%" or, worse, "+∞". A trend nobody can compute is a
 * trend the card does not draw.
 */
export function trend(current: number, previous: number | null): number | null {
  if (previous === null || previous === 0) {
    return null;
  }

  return (current - previous) / previous;
}

/** "+12,8%" / "-3,1%", in Vietnamese decimal notation. */
export function formatTrend(value: number): string {
  const percent = Math.abs(value * 100);
  const rounded = percent >= 100 ? Math.round(percent) : Math.round(percent * 10) / 10;

  return `${value >= 0 ? '+' : '-'}${String(rounded).replace('.', ',')}%`;
}

/** The statuses behind a slice, spelled out for a tooltip. */
export function sliceDescription(slice: StatusSlice): string {
  return slice.statuses.map((status) => ORDER_STATUS_LABELS[status]).join(', ');
}
