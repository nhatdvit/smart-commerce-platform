/**
 * The orders screen's data layer: the API contract on one side, the shape the
 * queue renders on the other.
 *
 * Two things are deliberately NOT decided here.
 *
 * Which moves are legal is the server's answer. `forwardStatuses` below
 * mirrors the state machine so the dialog can offer the right button, but the
 * server validates every transition against the committed status under a row
 * lock and answers 409 when a screen is stale. A client that disagrees loses.
 *
 * Whether an order is paid is the payment's own status, never the order's. An
 * order can be COMPLETED with its payment still PENDING - that is an
 * uncollected cash order - so the two are kept apart all the way to the screen
 * (CLAUDE.md section 6).
 */

import { api } from './api-client';

export const ORDER_STATUSES = [
  'PENDING_PAYMENT',
  'PLACED',
  'CONFIRMED',
  'PREPARING',
  'READY',
  'COMPLETED',
  'CANCELLED',
  'EXPIRED',
] as const;

export type OrderStatus = (typeof ORDER_STATUSES)[number];

export const PAYMENT_METHODS = ['CASH', 'BANK_TRANSFER'] as const;

export type PaymentMethod = (typeof PAYMENT_METHODS)[number];

export const PAYMENT_STATUSES = [
  'PENDING',
  'PROCESSING',
  'PAID',
  'FAILED',
  'PARTIALLY_REFUNDED',
  'REFUNDED',
] as const;

export type PaymentStatus = (typeof PAYMENT_STATUSES)[number];

export type FulfillmentType = 'PICKUP' | 'DELIVERY';

export const ORDER_STATUS_LABELS: Readonly<Record<OrderStatus, string>> = {
  PENDING_PAYMENT: 'Chờ chuyển khoản',
  PLACED: 'Chờ xác nhận',
  CONFIRMED: 'Đã xác nhận',
  PREPARING: 'Đang chuẩn bị',
  READY: 'Sẵn sàng',
  COMPLETED: 'Hoàn thành',
  CANCELLED: 'Đã hủy',
  EXPIRED: 'Hết hạn',
};

export const PAYMENT_METHOD_LABELS: Readonly<Record<PaymentMethod, string>> = {
  CASH: 'Tiền mặt',
  BANK_TRANSFER: 'Chuyển khoản',
};

export const PAYMENT_STATUS_LABELS: Readonly<Record<PaymentStatus, string>> = {
  PENDING: 'Chờ thanh toán',
  PROCESSING: 'Chờ xác nhận thanh toán',
  PAID: 'Đã thanh toán',
  FAILED: 'Thất bại',
  PARTIALLY_REFUNDED: 'Hoàn một phần',
  REFUNDED: 'Đã hoàn tiền',
};

export const FULFILLMENT_LABELS: Readonly<Record<FulfillmentType, string>> = {
  PICKUP: 'Nhận tại quán',
  DELIVERY: 'Giao hàng',
};

/** The statuses staff still have something to do about; see `status_counts`. */
export const OPEN_ORDER_STATUSES: readonly OrderStatus[] = [
  'PENDING_PAYMENT',
  'PLACED',
  'CONFIRMED',
  'PREPARING',
  'READY',
];

/** Open orders per status, for the queue badge. Counted by the server. */
export type OrderStatusCounts = Readonly<Partial<Record<OrderStatus, number>>>;

export interface OrderLinePreview {
  readonly name: string;
  readonly variant: string;
  readonly quantity: number;
}

/** One row of the queue. */
export interface AdminOrder {
  /** The UUID, for every later request. */
  readonly id: string;
  /** The short code staff and customers say out loud. */
  readonly code: string;
  readonly status: OrderStatus;
  readonly fulfillment: FulfillmentType;
  readonly contactName: string;
  /** Integer đồng, as the API sends it. */
  readonly total: number;
  readonly lineCount: number;
  readonly unitCount: number;
  readonly items: readonly OrderLinePreview[];
  /** Null when the order has no payment row - a real anomaly, shown as one. */
  readonly paymentMethod: PaymentMethod | null;
  readonly paymentStatus: PaymentStatus | null;
  /** ISO instants. The server stores UTC; the browser renders local time. */
  readonly scheduledAt: string | null;
  readonly placedAt: string | null;
  readonly createdAt: string | null;
}

export interface OrderLine extends OrderLinePreview {
  readonly id: string;
  readonly unitPrice: number;
  readonly lineTotal: number;
  readonly note: string | null;
}

export interface OrderEvent {
  readonly from: OrderStatus | null;
  readonly to: OrderStatus;
  /** Null when the system made the move, or the account is gone. */
  readonly actorName: string | null;
  readonly reason: string | null;
  readonly at: string | null;
}

/** One order in full, as the detail dialog shows it. */
export interface AdminOrderDetail extends AdminOrder {
  readonly contactPhone: string;
  readonly contactEmail: string | null;
  readonly deliveryAddress: string | null;
  readonly note: string | null;
  /** Null when the order has no payment row at all; see `paymentMethod`. */
  readonly paymentId: string | null;
  readonly lines: readonly OrderLine[];
  readonly subtotal: number;
  readonly discount: number;
  readonly deliveryFee: number;
  readonly tax: number;
  readonly paidAt: string | null;
  readonly paymentExpiresAt: string | null;
  readonly history: readonly OrderEvent[];
}

export interface OrderQuery {
  readonly statuses?: readonly OrderStatus[];
  readonly search?: string;
  /** The queue default is oldest first; a list screen wants the opposite. */
  readonly newestFirst?: boolean;
  readonly limit?: number;
}

/**
 * What every screen asks for unless it says otherwise.
 *
 * Newest first, because these are list screens: the dashboard shows the
 * orders that just came in, and the orders screen is read top-down. The API's
 * own default is the opposite - oldest first - because a kitchen queue works
 * the other way round, and that screen will ask for it when it is built.
 */
export const DEFAULT_ORDER_QUERY: OrderQuery = { newestFirst: true, limit: 50 };

export interface OrderPage {
  readonly orders: readonly AdminOrder[];
  readonly counts: OrderStatusCounts;
  readonly nextCursor: string | null;
  readonly hasMore: boolean;
  /**
   * Store-wide, like `counts` - of this store's `PENDING_PAYMENT` orders, how
   * many a customer has already reported as transferred. Genuinely waiting
   * on staff, as opposed to one nobody has acted on yet (CLAUDE.md section
   * 6 - a display grouping, never an order status of its own).
   */
  readonly awaitingPaymentConfirmationCount: number;
}

interface Money {
  readonly amount: string;
  readonly currency: string;
}

interface ApiLinePreview {
  readonly product_name: string;
  readonly variant_name: string;
  readonly quantity: number;
}

interface ApiOrderSummary {
  readonly id: string;
  readonly order_code: string;
  readonly status: string;
  readonly payment_status: string | null;
  readonly payment_method: string | null;
  readonly fulfillment_type: string;
  readonly total: Money;
  readonly item_count: number;
  readonly unit_count: number;
  readonly contact_name: string | null;
  readonly items: readonly ApiLinePreview[];
  readonly scheduled_at: string | null;
  readonly placed_at: string | null;
  readonly created_at: string | null;
}

interface ApiOrderItem extends ApiLinePreview {
  readonly id: string;
  readonly note: string | null;
  readonly unit_price: Money;
  readonly line_total: Money;
}

interface ApiOrder {
  readonly id: string;
  readonly order_code: string;
  readonly status: string;
  readonly fulfillment_type: string;
  readonly scheduled_at: string | null;
  readonly contact: {
    readonly name: string;
    readonly phone: string;
    readonly email: string | null;
  };
  readonly delivery_address_line: string | null;
  readonly note: string | null;
  readonly items: readonly ApiOrderItem[];
  readonly totals: {
    readonly subtotal: Money;
    readonly discount: Money;
    readonly delivery_fee: Money;
    readonly tax: Money;
    readonly total: Money;
  };
  readonly payment: {
    readonly id: string;
    readonly method: string;
    readonly status: string;
    readonly amount: Money;
    readonly expires_at: string | null;
    readonly paid_at: string | null;
  } | null;
  readonly status_history: readonly {
    readonly from_status: string | null;
    readonly to_status: string;
    readonly actor_name: string | null;
    readonly reason: string | null;
    readonly created_at: string | null;
  }[];
  readonly placed_at: string | null;
  readonly created_at: string | null;
}

interface ApiOrderPage {
  readonly items: readonly ApiOrderSummary[];
  readonly status_counts: Readonly<Record<string, number>>;
  readonly next_cursor: string | null;
  readonly has_more: boolean;
  readonly awaiting_payment_confirmation_count: number;
}

const UNKNOWN_CUSTOMER = 'Khách lẻ';

/**
 * Integer đồng from the transport string.
 *
 * `Number` rather than `BigInt` because these are displayed, not summed: an
 * order total is far inside a safe integer. The API's string form is still the
 * contract (CLAUDE.md section 6).
 */
function toAmount(money: Money): number {
  return Number.parseInt(money.amount, 10);
}

/**
 * Narrows a server value through the known list rather than casting it.
 *
 * An unexpected status - a state added to the API before this app knows about
 * it - must not reach a `Record` lookup and render `undefined` as a label.
 */
function toOrderStatus(value: string): OrderStatus {
  return ORDER_STATUSES.find((status) => status === value) ?? 'PLACED';
}

/** Null rather than a guess: no payment row is a state worth showing. */
function toPaymentMethod(value: string | null): PaymentMethod | null {
  return PAYMENT_METHODS.find((method) => method === value) ?? null;
}

function toPaymentStatus(value: string | null): PaymentStatus | null {
  return PAYMENT_STATUSES.find((status) => status === value) ?? null;
}

function toFulfillment(value: string): FulfillmentType {
  return value === 'DELIVERY' ? 'DELIVERY' : 'PICKUP';
}

function toLinePreview(line: ApiLinePreview): OrderLinePreview {
  return { name: line.product_name, variant: line.variant_name, quantity: line.quantity };
}

function toOrder(summary: ApiOrderSummary): AdminOrder {
  return {
    id: summary.id,
    code: summary.order_code,
    status: toOrderStatus(summary.status),
    fulfillment: toFulfillment(summary.fulfillment_type),
    contactName: summary.contact_name ?? UNKNOWN_CUSTOMER,
    total: toAmount(summary.total),
    lineCount: summary.item_count,
    unitCount: summary.unit_count,
    items: summary.items.map(toLinePreview),
    paymentMethod: toPaymentMethod(summary.payment_method),
    paymentStatus: toPaymentStatus(summary.payment_status),
    scheduledAt: summary.scheduled_at,
    placedAt: summary.placed_at,
    createdAt: summary.created_at,
  };
}

function toDetail(order: ApiOrder): AdminOrderDetail {
  const payment = order.payment;

  return {
    id: order.id,
    code: order.order_code,
    status: toOrderStatus(order.status),
    fulfillment: toFulfillment(order.fulfillment_type),
    contactName: order.contact.name,
    contactPhone: order.contact.phone,
    contactEmail: order.contact.email,
    deliveryAddress: order.delivery_address_line,
    note: order.note,
    total: toAmount(order.totals.total),
    subtotal: toAmount(order.totals.subtotal),
    discount: toAmount(order.totals.discount),
    deliveryFee: toAmount(order.totals.delivery_fee),
    tax: toAmount(order.totals.tax),
    lineCount: order.items.length,
    unitCount: order.items.reduce((units, item) => units + item.quantity, 0),
    items: order.items.map(toLinePreview),
    lines: order.items.map((item) => ({
      id: item.id,
      name: item.product_name,
      variant: item.variant_name,
      quantity: item.quantity,
      unitPrice: toAmount(item.unit_price),
      lineTotal: toAmount(item.line_total),
      note: item.note,
    })),
    paymentId: payment?.id ?? null,
    paymentMethod: toPaymentMethod(payment?.method ?? null),
    paymentStatus: toPaymentStatus(payment?.status ?? null),
    paidAt: payment?.paid_at ?? null,
    paymentExpiresAt: payment?.expires_at ?? null,
    scheduledAt: order.scheduled_at,
    placedAt: order.placed_at,
    createdAt: order.created_at,
    history: order.status_history.map((event) => ({
      from: event.from_status === null ? null : toOrderStatus(event.from_status),
      to: toOrderStatus(event.to_status),
      actorName: event.actor_name,
      reason: event.reason,
      at: event.created_at,
    })),
  };
}

function toPage(page: ApiOrderPage): OrderPage {
  const counts: Partial<Record<OrderStatus, number>> = {};

  for (const status of OPEN_ORDER_STATUSES) {
    const count = page.status_counts[status];
    if (typeof count === 'number') {
      counts[status] = count;
    }
  }

  return {
    orders: page.items.map(toOrder),
    counts,
    nextCursor: page.next_cursor,
    hasMore: page.has_more,
    awaitingPaymentConfirmationCount: page.awaiting_payment_confirmation_count,
  };
}

function queryString(query: OrderQuery, cursor?: string): string {
  const parameters = new URLSearchParams();

  for (const status of query.statuses ?? []) {
    parameters.append('status[]', status);
  }

  if (query.search !== undefined && query.search.trim() !== '') {
    parameters.set('q', query.search.trim());
  }

  if (query.newestFirst === true) {
    parameters.set('sort', 'newest_first');
  }

  if (query.limit !== undefined) {
    parameters.set('limit', String(query.limit));
  }

  if (cursor !== undefined) {
    parameters.set('cursor', cursor);
  }

  const encoded = parameters.toString();

  return encoded === '' ? '' : `?${encoded}`;
}

/**
 * A stable key for one query, so a screen can tell whether it is already
 * showing exactly this list and skip a second identical request.
 */
export function orderQueryKey(query: OrderQuery): string {
  return queryString(query);
}

/**
 * A page of the store's orders.
 *
 * The store comes from the host the request arrives on, never from the client,
 * so there is no store id to pass (CLAUDE.md section 8).
 */
export async function loadOrders(query: OrderQuery = {}, cursor?: string): Promise<OrderPage> {
  return toPage(await api.get<ApiOrderPage>(`/admin/orders${queryString(query, cursor)}`));
}

export async function loadOrderDetail(orderId: string): Promise<AdminOrderDetail> {
  return toDetail(await api.get<ApiOrder>(`/admin/orders/${orderId}`));
}

/**
 * Moves one order, and answers with the order as it now stands.
 *
 * The target status is sent explicitly rather than "next": two staff members
 * tapping at once would both mean something different by "next", and the
 * second tap would move the order a step further than anyone intended. Sending
 * the status the operator saw turns that race into a 409 they can see.
 */
export async function transitionOrder(
  orderId: string,
  toStatus: OrderStatus,
  reason?: string,
): Promise<AdminOrderDetail> {
  return toDetail(
    await api.post<ApiOrder>(`/admin/orders/${orderId}/status`, {
      to_status: toStatus,
      ...(reason === undefined ? {} : { reason }),
    }),
  );
}

/**
 * The forward step a staff member can take from here, if any - from the
 * order's status alone, which is enough for every status except
 * `PENDING_PAYMENT` (see {@see confirmableStatus}, which also looks at how
 * it is being paid).
 *
 * A mirror of the server's state machine, for choosing a button label.
 * CANCELLED is not a step forward; it has its own control and needs a
 * reason, so it is deliberately absent too.
 */
export function forwardStatus(status: OrderStatus): OrderStatus | undefined {
  switch (status) {
    case 'PLACED':
      return 'CONFIRMED';
    case 'CONFIRMED':
      return 'PREPARING';
    case 'PREPARING':
      return 'READY';
    case 'READY':
      return 'COMPLETED';
    default:
      return undefined;
  }
}

/**
 * The forward step for a button labelled "Xác nhận đơn", which - unlike
 * {@see forwardStatus} - also has to answer for `PENDING_PAYMENT`: confirming
 * the order is a different decision from confirming its payment (CLAUDE.md
 * section 6, "Order confirmation independent of payment confirmation",
 * approved 2026-11-25), so once a customer has reported the transfer
 * (`paymentStatus === 'PROCESSING'`), staff may confirm the order itself
 * right away, before anyone has checked the real bank credit. An order still
 * `PENDING_PAYMENT` with nothing reported has nothing to confirm yet - this
 * is only ever a hint for the button; the server re-checks the real payment
 * status itself.
 */
export function confirmableStatus(order: {
  readonly status: OrderStatus;
  readonly paymentStatus: PaymentStatus | null;
}): OrderStatus | undefined {
  if (order.status === 'PENDING_PAYMENT') {
    return order.paymentStatus === 'PROCESSING' ? 'CONFIRMED' : undefined;
  }

  return forwardStatus(order.status);
}

/** Whether this order can still be cancelled. The server decides for real. */
export function canCancel(status: OrderStatus): boolean {
  return status !== 'COMPLETED' && status !== 'CANCELLED' && status !== 'EXPIRED';
}

/**
 * The CSS tone for a status badge.
 *
 * Five tones for eight statuses, because what a glance needs to know is
 * "waiting, working, ready, done, or gone wrong" - not which of three ways an
 * order ended.
 */
export function statusTone(status: OrderStatus): string {
  switch (status) {
    case 'PENDING_PAYMENT':
    case 'PLACED':
      return 'waiting';
    case 'CONFIRMED':
    case 'PREPARING':
      return 'preparing';
    case 'READY':
      return 'ready';
    case 'COMPLETED':
      return 'done';
    default:
      return 'cancelled';
  }
}

/**
 * "07:15" from an ISO instant, in the viewer's timezone.
 *
 * Called during render, which is safe because the orders are fetched in the
 * browser: nothing here is part of the server-rendered HTML, so there is no
 * hydration mismatch to worry about (unlike `useTodayLabel`).
 */
export function timeLabel(iso: string | null): string {
  if (iso === null) {
    return '—';
  }

  const at = new Date(iso);

  return Number.isNaN(at.getTime())
    ? '—'
    : at.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
}

/** "14:02 20/11" - enough to read a timeline without a wall of text. */
export function dateTimeLabel(iso: string | null): string {
  if (iso === null) {
    return '—';
  }

  const at = new Date(iso);

  return Number.isNaN(at.getTime())
    ? '—'
    : `${at.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' })} ${at.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' })}`;
}

/** "2 Bánh mì pate trứng, 1 Cà phê sữa" - the queue row's one-line summary. */
export function itemsSummary(items: readonly OrderLinePreview[]): string {
  if (items.length === 0) {
    return 'Không có món nào';
  }

  return items.map((item) => `${item.quantity} ${item.name}`).join(', ');
}
