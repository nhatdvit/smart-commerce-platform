/**
 * Reading a window of orders, one page at a time.
 *
 * The API pages at 100 and has no aggregate endpoint yet, so the dashboard
 * reads the window itself. Two things keep that honest:
 *
 *   - a hard cap, so a busy month can never turn one screen into forty
 *     requests. When the cap is reached the screen says so rather than
 *     reporting a total it knows is short (spec section 2.6).
 *   - an `AbortSignal`, so switching from "30 ngày" to "Hôm nay" abandons the
 *     reads nobody is waiting for instead of racing them to the state.
 *
 * Replacing this with `GET /admin/reports/overview` later touches this file
 * and nothing else.
 */

import { loadStoreContext } from './store-api';
import { DEFAULT_TIMEZONE, type DateRange } from './dashboard-range';
import { loadOrders, type AdminOrder } from './orders-api';

/** The API's own maximum, so the fewest possible round trips are made. */
export const PAGE_SIZE = 100;

/** 1.000 orders. Beyond this the screen reports what it read, and says so. */
export const MAX_PAGES = 10;

export interface RangeOrders {
  readonly orders: readonly AdminOrder[];
  /** True when the cap stopped the read while the window still had orders. */
  readonly capped: boolean;
}

export async function loadOrdersInRange(
  range: DateRange,
  signal?: AbortSignal,
): Promise<RangeOrders> {
  const orders: AdminOrder[] = [];
  let cursor: string | undefined;

  for (let page = 0; page < MAX_PAGES; page += 1) {
    const result = await loadOrders(
      {
        newestFirst: true,
        limit: PAGE_SIZE,
        placedFrom: range.fromIso,
        placedTo: range.toIso,
      },
      cursor,
      signal,
    );

    orders.push(...result.orders);

    if (!result.hasMore || result.nextCursor === null) {
      return { orders, capped: false };
    }

    cursor = result.nextCursor;
  }

  return { orders, capped: true };
}

/**
 * The store's timezone, asked for once per page life.
 *
 * Every range boundary depends on it and it cannot change while someone is
 * looking at the screen, so a second request would only ever return the same
 * answer. A failure falls back to the configured default rather than blocking
 * the dashboard: a wrong-by-one-hour boundary is recoverable, a blank screen
 * is not.
 */
let timeZoneRequest: Promise<string> | undefined;

export async function storeTimeZone(): Promise<string> {
  timeZoneRequest ??= loadStoreContext()
    .then((store) => (store.timezone === '' ? DEFAULT_TIMEZONE : store.timezone))
    .catch(() => DEFAULT_TIMEZONE);

  return timeZoneRequest;
}
