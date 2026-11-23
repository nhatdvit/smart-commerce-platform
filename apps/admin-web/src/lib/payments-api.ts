/**
 * The reconciliation queue's data layer.
 *
 * MVP settles in `CASH` or `BANK_TRANSFER` only (CLAUDE.md section 6). The
 * one thing every screen built on this file must never do is let a customer
 * action - a QR having been shown, a screenshot, "I have transferred" - read
 * as proof of payment: the only way a payment becomes `PAID` is a named staff
 * member calling `confirmPayment`, after seeing the real cash or the real
 * bank credit.
 */

import { ApiError, api, newIdempotencyKey } from './api-client';

export const PAYMENT_METHODS = ['CASH', 'BANK_TRANSFER'] as const;

export type PaymentMethod = (typeof PAYMENT_METHODS)[number];

export const PAYMENT_METHOD_LABELS: Readonly<Record<PaymentMethod, string>> = {
  CASH: 'Tiền mặt',
  BANK_TRANSFER: 'Chuyển khoản',
};

export const PAYMENT_STATUSES = [
  'PENDING',
  'PROCESSING',
  'PAID',
  'FAILED',
  'PARTIALLY_REFUNDED',
  'REFUNDED',
] as const;

export type PaymentStatus = (typeof PAYMENT_STATUSES)[number];

export const PAYMENT_STATUS_LABELS: Readonly<Record<PaymentStatus, string>> = {
  PENDING: 'Chờ thanh toán',
  PROCESSING: 'Đang xử lý',
  PAID: 'Đã thanh toán',
  FAILED: 'Thất bại',
  PARTIALLY_REFUNDED: 'Hoàn một phần',
  REFUNDED: 'Đã hoàn tiền',
};

/** The CSS tone for a status badge; see StatusBadge for the palette. */
export function paymentStatusTone(status: PaymentStatus): string {
  switch (status) {
    case 'PAID':
      return 'ready';
    case 'PENDING':
    case 'PROCESSING':
      return 'waiting';
    case 'FAILED':
      return 'cancelled';
    default:
      return 'done';
  }
}

export const DISCREPANCY_TYPES = [
  'UNDERPAID',
  'OVERPAID',
  'MISSING_REFERENCE',
  'UNKNOWN_REFERENCE',
  'DUPLICATE_TRANSFER',
  'LATE_TRANSFER',
  'CANCELLED_ORDER_PAID',
] as const;

export type DiscrepancyType = (typeof DISCREPANCY_TYPES)[number];

export const DISCREPANCY_TYPE_LABELS: Readonly<Record<DiscrepancyType, string>> = {
  UNDERPAID: 'Thiếu tiền',
  OVERPAID: 'Thừa tiền',
  MISSING_REFERENCE: 'Không có mã tham chiếu',
  UNKNOWN_REFERENCE: 'Mã tham chiếu không khớp đơn nào',
  DUPLICATE_TRANSFER: 'Chuyển khoản trùng lặp',
  LATE_TRANSFER: 'Chuyển khoản đến muộn',
  CANCELLED_ORDER_PAID: 'Đơn đã hủy nhưng có tiền về',
};

export interface PaymentDiscrepancy {
  readonly type: DiscrepancyType;
  readonly expectedAmount: number | null;
  readonly observedAmount: number | null;
  readonly note: string | null;
  readonly recordedBy: string | null;
  readonly recordedAt: string | null;
}

export interface PaymentEvent {
  readonly eventType: string;
  readonly fromStatus: PaymentStatus | null;
  readonly toStatus: PaymentStatus | null;
  readonly actorName: string | null;
  readonly createdAt: string | null;
}

/** A payment as staff see it, with the trail of who did what. */
export interface AdminPayment {
  readonly id: string;
  readonly orderId: string;
  readonly orderCode: string;
  readonly customerName: string;
  readonly storeId: string | null;
  readonly method: PaymentMethod;
  readonly status: PaymentStatus;
  /** What the order is owed, in whole đồng. */
  readonly amount: number;
  /** What staff recorded as actually received. */
  readonly confirmedAmount: number | null;
  readonly transferReference: string | null;
  /**
   * The raw EMVCo/VietQR string, for rendering a scannable code. Never proof
   * of anything itself - staff verify a transfer against bankAccountMasked
   * and transferReference, not this.
   */
  readonly qrPayload: string | null;
  readonly bankTransactionReference: string | null;
  readonly bankAccountMasked: string | null;
  /** The person who said the money arrived. Never a system name. */
  readonly confirmedBy: string | null;
  readonly confirmedAt: string | null;
  readonly expiresAt: string | null;
  readonly needsAttention: boolean;
  readonly discrepancies: readonly PaymentDiscrepancy[];
  readonly events: readonly PaymentEvent[];
}

export interface PaymentListQuery {
  readonly statuses?: readonly PaymentStatus[];
  readonly method?: PaymentMethod | null;
  readonly needsAttention?: boolean;
  readonly transferReference?: string;
  readonly cursor?: string | null;
}

export interface PaymentPage {
  readonly items: readonly AdminPayment[];
  readonly nextCursor: string | null;
  readonly hasMore: boolean;
}

export interface ConfirmPaymentInput {
  /** Whole đồng actually received. */
  readonly amount: number;
  readonly bankTransactionReference?: string | null;
  readonly acceptDiscrepancy?: boolean;
  readonly note?: string | null;
}

export interface FlagPaymentInput {
  readonly type: DiscrepancyType;
  readonly observedAmount?: number | null;
  readonly note?: string | null;
}

interface ApiMoney {
  readonly amount: string;
  readonly currency: string;
}

function toAmount(value: ApiMoney | null): number | null {
  return value === null ? null : Number.parseInt(value.amount, 10);
}

function toStatus(value: string): PaymentStatus {
  return PAYMENT_STATUSES.find((status) => status === value) ?? 'PENDING';
}

function toMethod(value: string): PaymentMethod {
  return PAYMENT_METHODS.find((method) => method === value) ?? 'CASH';
}

function toDiscrepancyType(value: string): DiscrepancyType {
  return DISCREPANCY_TYPES.find((type) => type === value) ?? 'UNKNOWN_REFERENCE';
}

interface ApiPaymentDiscrepancy {
  readonly type: string;
  readonly expected_amount: ApiMoney | null;
  readonly observed_amount: ApiMoney | null;
  readonly note: string | null;
  readonly recorded_by: string | null;
  readonly recorded_at: string | null;
}

interface ApiPaymentEvent {
  readonly event_type: string;
  readonly from_status: string | null;
  readonly to_status: string | null;
  readonly actor_name: string | null;
  readonly created_at: string | null;
}

interface ApiAdminPayment {
  readonly id: string;
  readonly order_id: string;
  readonly order_code: string;
  readonly customer_name: string;
  readonly store_id: string | null;
  readonly method: string;
  readonly status: string;
  readonly amount: ApiMoney;
  readonly confirmed_amount: ApiMoney | null;
  readonly transfer_reference: string | null;
  readonly qr_payload: string | null;
  readonly bank_transaction_reference: string | null;
  readonly bank_account_masked: string | null;
  readonly confirmed_by: string | null;
  readonly confirmed_at: string | null;
  readonly expires_at: string | null;
  readonly needs_attention: boolean;
  readonly discrepancies: readonly ApiPaymentDiscrepancy[];
  readonly events: readonly ApiPaymentEvent[];
}

interface ApiPaymentPage {
  readonly items: readonly ApiAdminPayment[];
  readonly next_cursor: string | null;
  readonly has_more: boolean;
}

function toPayment(payment: ApiAdminPayment): AdminPayment {
  return {
    id: payment.id,
    orderId: payment.order_id,
    orderCode: payment.order_code,
    customerName: payment.customer_name,
    storeId: payment.store_id,
    method: toMethod(payment.method),
    status: toStatus(payment.status),
    amount: toAmount(payment.amount) ?? 0,
    confirmedAmount: toAmount(payment.confirmed_amount),
    transferReference: payment.transfer_reference,
    qrPayload: payment.qr_payload,
    bankTransactionReference: payment.bank_transaction_reference,
    bankAccountMasked: payment.bank_account_masked,
    confirmedBy: payment.confirmed_by,
    confirmedAt: payment.confirmed_at,
    expiresAt: payment.expires_at,
    needsAttention: payment.needs_attention,
    discrepancies: payment.discrepancies.map((discrepancy) => ({
      type: toDiscrepancyType(discrepancy.type),
      expectedAmount: toAmount(discrepancy.expected_amount),
      observedAmount: toAmount(discrepancy.observed_amount),
      note: discrepancy.note,
      recordedBy: discrepancy.recorded_by,
      recordedAt: discrepancy.recorded_at,
    })),
    events: payment.events.map((event) => ({
      eventType: event.event_type,
      fromStatus: event.from_status === null ? null : toStatus(event.from_status),
      toStatus: event.to_status === null ? null : toStatus(event.to_status),
      actorName: event.actor_name,
      createdAt: event.created_at,
    })),
  };
}

function toQueryString(query: PaymentListQuery): string {
  const params = new URLSearchParams();

  for (const status of query.statuses ?? []) {
    params.append('status[]', status);
  }

  if (query.method !== undefined && query.method !== null) {
    params.set('method', query.method);
  }

  if (query.needsAttention === true) {
    params.set('needs_attention', 'true');
  }

  if (query.transferReference !== undefined && query.transferReference.trim() !== '') {
    params.set('transfer_reference', query.transferReference.trim());
  }

  if (query.cursor !== undefined && query.cursor !== null) {
    params.set('cursor', query.cursor);
  }

  const search = params.toString();

  return search === '' ? '' : `?${search}`;
}

/** The reconciliation queue, one page of it. */
export async function loadPayments(query: PaymentListQuery = {}): Promise<PaymentPage> {
  const page = await api.get<ApiPaymentPage>(`/admin/payments${toQueryString(query)}`);

  return {
    items: page.items.map(toPayment),
    nextCursor: page.next_cursor,
    hasMore: page.has_more,
  };
}

export async function loadPayment(paymentId: string): Promise<AdminPayment> {
  return toPayment(await api.get<ApiAdminPayment>(`/admin/payments/${paymentId}`));
}

/**
 * Records that the money actually arrived. The only way a payment becomes
 * `PAID` - never a customer action, never automatic (CLAUDE.md section 6).
 *
 * A fresh idempotency key per call: a double-click on the same dialog state
 * must not record two confirmations, but the server's own
 * `PAYMENT_ALREADY_CONFIRMED` is what refuses an operator's second, genuinely
 * separate confirmation of an already-settled payment.
 */
export async function confirmPayment(
  paymentId: string,
  input: ConfirmPaymentInput,
): Promise<AdminPayment> {
  const payment = await api.post<ApiAdminPayment>(
    `/admin/payments/${paymentId}/confirm`,
    {
      amount: String(input.amount),
      bank_transaction_reference: input.bankTransactionReference ?? null,
      accept_discrepancy: input.acceptDiscrepancy ?? false,
      note: input.note ?? null,
    },
    { 'Idempotency-Key': newIdempotencyKey() },
  );

  return toPayment(payment);
}

export async function flagPayment(
  paymentId: string,
  input: FlagPaymentInput,
): Promise<AdminPayment> {
  const payment = await api.post<ApiAdminPayment>(`/admin/payments/${paymentId}/flag`, {
    type: input.type,
    observed_amount:
      input.observedAmount === undefined || input.observedAmount === null
        ? null
        : String(input.observedAmount),
    note: input.note ?? null,
  });

  return toPayment(payment);
}

/**
 * Replaces the VietQR on a payment still awaiting its transfer - an expired
 * window or a lost QR. The server refuses this for anything already settled
 * or not a bank transfer; see GenerateBankTransferInstructions.
 */
export async function regeneratePaymentQr(paymentId: string): Promise<AdminPayment> {
  return toPayment(
    await api.post<ApiAdminPayment>(`/admin/payments/${paymentId}/regenerate-qr`, {}),
  );
}

/** Where a store's bank transfers are received, masked. */
export interface StoreBankAccount {
  readonly id: string;
  readonly bankBin: string;
  readonly bankName: string;
  readonly accountNumberMasked: string;
  readonly accountName: string;
  readonly isDefault: boolean;
  readonly isActive: boolean;
  readonly updatedAt: string | null;
  readonly updatedBy: string | null;
}

export interface BankAccountDraft {
  readonly bankBin: string;
  readonly accountNumber: string;
  readonly accountName: string;
}

interface ApiStoreBankAccount {
  readonly id: string;
  readonly bank_bin: string;
  readonly bank_name: string;
  readonly account_number_masked: string;
  readonly account_name: string;
  readonly is_default: boolean;
  readonly is_active: boolean;
  readonly updated_at: string | null;
  readonly updated_by: string | null;
}

function toBankAccount(account: ApiStoreBankAccount): StoreBankAccount {
  return {
    id: account.id,
    bankBin: account.bank_bin,
    bankName: account.bank_name,
    accountNumberMasked: account.account_number_masked,
    accountName: account.account_name,
    isDefault: account.is_default,
    isActive: account.is_active,
    updatedAt: account.updated_at,
    updatedBy: account.updated_by,
  };
}

/** One entry in the known VietQR bank directory, for a picker. */
export interface KnownBankOption {
  readonly bin: string;
  readonly name: string;
}

interface ApiKnownBankPage {
  readonly items: readonly KnownBankOption[];
}

/**
 * Every bank this deployment recognises, for a `<select>` rather than a
 * free-typed BIN - an operator who picks "Vietcombank" by name cannot
 * transpose a digit into a different, real bank's identifier.
 */
export async function loadKnownBanks(): Promise<readonly KnownBankOption[]> {
  return (await api.get<ApiKnownBankPage>('/admin/known-banks')).items;
}

/** Null when this store has not configured one yet (a 404 is not an error here). */
export async function loadBankAccount(storeId: string): Promise<StoreBankAccount | null> {
  try {
    return toBankAccount(
      await api.get<ApiStoreBankAccount>(`/admin/stores/${storeId}/bank-account`),
    );
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) {
      return null;
    }

    throw error;
  }
}

export async function updateBankAccount(
  storeId: string,
  draft: BankAccountDraft,
): Promise<StoreBankAccount> {
  return toBankAccount(
    await api.put<ApiStoreBankAccount>(`/admin/stores/${storeId}/bank-account`, {
      bank_bin: draft.bankBin,
      account_number: draft.accountNumber,
      account_name: draft.accountName,
      is_default: true,
    }),
  );
}

export interface PaymentMethodOption {
  readonly method: PaymentMethod;
  readonly enabled: boolean;
  readonly label: string;
  readonly unavailableReason: string | null;
}

interface ApiPaymentMethodOption {
  readonly method: string;
  readonly enabled: boolean;
  readonly label: string;
  readonly description: string | null;
  readonly unavailable_reason: string | null;
}

interface ApiPaymentMethodPage {
  readonly items: readonly ApiPaymentMethodOption[];
}

function toMethodOption(option: ApiPaymentMethodOption): PaymentMethodOption {
  return {
    method: toMethod(option.method),
    enabled: option.enabled,
    label: option.label,
    unavailableReason: option.unavailable_reason,
  };
}

export async function loadPaymentMethodOptions(
  storeId: string,
): Promise<readonly PaymentMethodOption[]> {
  const page = await api.get<ApiPaymentMethodPage>(`/admin/stores/${storeId}/payment-methods`);

  return page.items.map(toMethodOption);
}

/**
 * Sets one method's switch.
 *
 * The other method is left out of the request entirely rather than sent back
 * unchanged: the server defaults an omitted method to its current setting,
 * and sending both every time would risk this screen overwriting a setting
 * another tab changed a moment ago with a value it never actually looked at.
 */
export async function setPaymentMethodEnabled(
  storeId: string,
  method: PaymentMethod,
  enabled: boolean,
): Promise<readonly PaymentMethodOption[]> {
  const page = await api.put<ApiPaymentMethodPage>(`/admin/stores/${storeId}/payment-methods`, {
    methods: [{ method, enabled }],
  });

  return page.items.map(toMethodOption);
}
