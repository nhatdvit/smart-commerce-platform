/**
 * Checkout: `POST /orders`, and `GET /payment-methods` for what a store
 * accepts. Session-or-guest, like `api-client.ts` already handles - a guest
 * checkout needs no sign-in at all.
 */
import { api } from './api-client';

export type FulfillmentType = 'PICKUP' | 'DELIVERY';
export type PaymentMethod = 'CASH' | 'BANK_TRANSFER';

export interface CreateOrderLine {
  readonly menuItemId: string;
  readonly quantity: number;
  readonly note?: string;
}

export interface CreateOrderInput {
  readonly fulfillmentType: FulfillmentType;
  readonly paymentMethod: PaymentMethod;
  readonly contactName: string;
  readonly contactPhone: string;
  readonly deliveryAddressLine?: string;
  readonly note?: string;
  readonly items: readonly CreateOrderLine[];
  /** A consent check against the server's own total - never an input to pricing. */
  readonly expectedTotalAmount: number;
}

interface ApiMoney {
  readonly amount: string;
  readonly currency: string;
}

interface ApiOrderItem {
  readonly id: string;
  readonly product_name: string;
  readonly variant_name: string;
  readonly quantity: number;
  readonly unit_price: ApiMoney;
  readonly line_total: ApiMoney;
}

interface ApiOrder {
  readonly id: string;
  readonly order_code: string;
  readonly status: string;
  readonly fulfillment_type: FulfillmentType;
  readonly contact: {
    readonly name: string;
    readonly phone: string;
    readonly email: string | null;
  };
  readonly delivery_address_line: string | null;
  readonly items: readonly ApiOrderItem[];
  readonly totals: {
    readonly subtotal: ApiMoney;
    readonly discount: ApiMoney;
    readonly delivery_fee: ApiMoney;
    readonly total: ApiMoney;
  };
  readonly created_at: string;
}

interface ApiBankTransferInstructions {
  readonly transfer_reference: string;
  readonly amount: ApiMoney;
  readonly bank: {
    readonly bin: string | null;
    readonly name: string | null;
    readonly account_number: string | null;
    readonly account_name: string | null;
  };
  readonly qr_payload: string | null;
  readonly expires_at: string | null;
}

interface ApiCashInstructions {
  readonly collect_at: 'PICKUP_COUNTER' | 'ON_DELIVERY';
  readonly note: string | null;
}

interface ApiOrderPayment {
  readonly payment: { readonly method: PaymentMethod; readonly status: string };
  readonly bank_transfer: ApiBankTransferInstructions | null;
  readonly cash: ApiCashInstructions | null;
}

interface ApiOrderCreated {
  readonly order: ApiOrder;
  readonly payment: ApiOrderPayment;
  readonly guest_order_token: string | null;
}

export interface OrderLine {
  readonly productName: string;
  readonly variantName: string;
  readonly quantity: number;
  readonly unitPrice: number;
  readonly lineTotal: number;
}

export interface BankTransferInstructions {
  readonly transferReference: string;
  readonly amount: number;
  readonly bankBin: string | null;
  readonly bankName: string | null;
  readonly accountNumber: string | null;
  readonly accountName: string | null;
  readonly qrPayload: string | null;
}

export interface CashInstructions {
  readonly collectAt: 'PICKUP_COUNTER' | 'ON_DELIVERY';
}

export interface OrderConfirmation {
  readonly orderId: string;
  readonly orderCode: string;
  readonly status: string;
  readonly contactName: string;
  readonly items: readonly OrderLine[];
  readonly total: number;
  readonly paymentMethod: PaymentMethod;
  readonly paymentStatus: string;
  readonly bankTransfer: BankTransferInstructions | null;
  readonly cash: CashInstructions | null;
  /** Only for a guest checkout - needed to read this order back later. */
  readonly guestOrderToken: string | null;
}

function toAmount(money: ApiMoney): number {
  return parseInt(money.amount, 10);
}

function toConfirmation(data: ApiOrderCreated): OrderConfirmation {
  const { order, payment } = data;

  return {
    orderId: order.id,
    orderCode: order.order_code,
    status: order.status,
    contactName: order.contact.name,
    items: order.items.map((item) => ({
      productName: item.product_name,
      variantName: item.variant_name,
      quantity: item.quantity,
      unitPrice: toAmount(item.unit_price),
      lineTotal: toAmount(item.line_total),
    })),
    total: toAmount(order.totals.total),
    paymentMethod: payment.payment.method,
    paymentStatus: payment.payment.status,
    bankTransfer:
      payment.bank_transfer === null
        ? null
        : {
            transferReference: payment.bank_transfer.transfer_reference,
            amount: toAmount(payment.bank_transfer.amount),
            bankBin: payment.bank_transfer.bank.bin,
            bankName: payment.bank_transfer.bank.name,
            accountNumber: payment.bank_transfer.bank.account_number,
            accountName: payment.bank_transfer.bank.account_name,
            qrPayload: payment.bank_transfer.qr_payload,
          },
    cash: payment.cash === null ? null : { collectAt: payment.cash.collect_at },
    guestOrderToken: data.guest_order_token,
  };
}

/** A fresh key for one checkout attempt - a new one per submit, including a retry after a genuine failure. */
export function newIdempotencyKey(): string {
  return crypto.randomUUID();
}

export async function createOrder(
  input: CreateOrderInput,
  idempotencyKey: string,
): Promise<OrderConfirmation> {
  const body = {
    fulfillment_type: input.fulfillmentType,
    payment_method: input.paymentMethod,
    contact: { name: input.contactName, phone: input.contactPhone },
    ...(input.deliveryAddressLine === undefined
      ? {}
      : { delivery_address_line: input.deliveryAddressLine }),
    ...(input.note === undefined ? {} : { note: input.note }),
    items: input.items.map((line) => ({
      menu_item_id: line.menuItemId,
      quantity: line.quantity,
      ...(line.note === undefined ? {} : { note: line.note }),
    })),
    expected_total_amount: String(input.expectedTotalAmount),
  };

  const data = await api.post<ApiOrderCreated>('/orders', body, {
    'Idempotency-Key': idempotencyKey,
  });

  return toConfirmation(data);
}

interface ApiOrderLinePreview {
  readonly product_name: string;
  readonly variant_name: string;
  readonly quantity: number;
}

interface ApiOrderSummary {
  readonly id: string;
  readonly order_code: string;
  readonly status: string;
  readonly payment_status: string | null;
  readonly payment_method: PaymentMethod | null;
  readonly fulfillment_type: FulfillmentType;
  readonly total: ApiMoney;
  readonly item_count: number;
  readonly unit_count: number;
  readonly items: readonly ApiOrderLinePreview[];
  readonly placed_at: string | null;
  readonly created_at: string;
}

interface ApiMyOrderPage {
  readonly items: readonly ApiOrderSummary[];
  readonly next_cursor: string | null;
  readonly has_more: boolean;
}

export interface OrderLinePreview {
  readonly productName: string;
  readonly variantName: string;
  readonly quantity: number;
}

export interface MyOrderSummary {
  readonly id: string;
  readonly orderCode: string;
  readonly status: string;
  readonly paymentStatus: string | null;
  readonly paymentMethod: PaymentMethod | null;
  readonly fulfillmentType: FulfillmentType;
  readonly total: number;
  readonly items: readonly OrderLinePreview[];
  readonly placedAt: string | null;
  readonly createdAt: string;
}

export interface MyOrdersPage {
  readonly items: readonly MyOrderSummary[];
  readonly nextCursor: string | null;
  readonly hasMore: boolean;
}

/** A signed-in customer's own order history - `GET /customer/orders`, newest first. */
export async function listMyOrders(cursor?: string): Promise<MyOrdersPage> {
  const query = cursor === undefined ? '' : `?cursor=${encodeURIComponent(cursor)}`;
  const data = await api.get<ApiMyOrderPage>(`/customer/orders${query}`);

  return {
    items: data.items.map((order) => ({
      id: order.id,
      orderCode: order.order_code,
      status: order.status,
      paymentStatus: order.payment_status,
      paymentMethod: order.payment_method,
      fulfillmentType: order.fulfillment_type,
      total: toAmount(order.total),
      items: order.items.map((item) => ({
        productName: item.product_name,
        variantName: item.variant_name,
        quantity: item.quantity,
      })),
      placedAt: order.placed_at,
      createdAt: order.created_at,
    })),
    nextCursor: data.next_cursor,
    hasMore: data.has_more,
  };
}

interface ApiOrderDetailItem {
  readonly product_id: string;
  readonly variant_id: string;
  readonly product_name: string;
  readonly variant_name: string;
  readonly quantity: number;
}

interface ApiOrderDetail {
  readonly id: string;
  readonly order_code: string;
  readonly status: string;
  readonly items: readonly ApiOrderDetailItem[];
}

export interface OrderDetailLine {
  readonly productId: string;
  readonly variantId: string;
  readonly productName: string;
  readonly variantName: string;
  readonly quantity: number;
}

export interface OrderDetail {
  readonly id: string;
  readonly orderCode: string;
  readonly status: string;
  readonly items: readonly OrderDetailLine[];
}

/** The full order, including `product_id`/`variant_id` per line - what "Đặt lại" needs to match against today's live menu. */
export async function getOrder(orderId: string): Promise<OrderDetail> {
  const data = await api.get<ApiOrderDetail>(`/orders/${orderId}`);

  return {
    id: data.id,
    orderCode: data.order_code,
    status: data.status,
    items: data.items.map((item) => ({
      productId: item.product_id,
      variantId: item.variant_id,
      productName: item.product_name,
      variantName: item.variant_name,
      quantity: item.quantity,
    })),
  };
}

/**
 * Self-service cancel - only before the kitchen starts (`CancelOrder`'s own
 * doc comment on the backend). The server is the one source of truth for
 * whether this succeeded; this only returns the new status for the caller to
 * reflect locally.
 */
export async function cancelOrder(orderId: string, reason?: string): Promise<string> {
  const body = reason === undefined || reason.trim() === '' ? {} : { reason: reason.trim() };
  const data = await api.post<{ readonly status: string }>(`/orders/${orderId}/cancel`, body);

  return data.status;
}

export interface PaymentMethodOption {
  readonly method: PaymentMethod;
  readonly enabled: boolean;
  readonly label: string;
  readonly unavailableReason: string | null;
}

interface ApiPaymentMethodOption {
  readonly method: PaymentMethod;
  readonly enabled: boolean;
  readonly label: string;
  readonly unavailable_reason: string | null;
}

export async function loadPaymentMethods(): Promise<readonly PaymentMethodOption[]> {
  const data = await api.get<{ readonly items: readonly ApiPaymentMethodOption[] }>(
    '/payment-methods',
  );

  return data.items.map((item) => ({
    method: item.method,
    enabled: item.enabled,
    label: item.label,
    unavailableReason: item.unavailable_reason,
  }));
}
