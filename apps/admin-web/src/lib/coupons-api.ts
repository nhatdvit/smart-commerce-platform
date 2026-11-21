/**
 * The promotions screen's data layer.
 *
 * Two things the server decides and this file only renders:
 *
 * `availability` - whether a code works right now - is computed by the API on
 * every read, because it depends on the clock. Deriving it here from the
 * dates would let the screen and the server disagree the moment a window
 * closes, and the customer at the counter is the one who finds out.
 *
 * `redeemed_count` is 0 for every code until checkout starts counting
 * redemptions. The screen says "chưa tính" rather than showing a zero that
 * reads as "nobody wanted it".
 */

import { api } from './api-client';
import { money } from './money';

export const COUPON_STATUSES = ['ACTIVE', 'PAUSED', 'ARCHIVED'] as const;

export type CouponStatus = (typeof COUPON_STATUSES)[number];

export const DISCOUNT_TYPES = ['PERCENTAGE', 'FIXED_AMOUNT'] as const;

export type DiscountType = (typeof DISCOUNT_TYPES)[number];

export const COUPON_AVAILABILITIES = [
  'RUNNING',
  'SCHEDULED',
  'EXPIRED',
  'EXHAUSTED',
  'PAUSED',
  'ARCHIVED',
] as const;

export type CouponAvailability = (typeof COUPON_AVAILABILITIES)[number];

export const COUPON_STATUS_LABELS: Readonly<Record<CouponStatus, string>> = {
  ACTIVE: 'Đang bật',
  PAUSED: 'Tạm dừng',
  ARCHIVED: 'Đã lưu trữ',
};

export const DISCOUNT_TYPE_LABELS: Readonly<Record<DiscountType, string>> = {
  PERCENTAGE: 'Giảm theo phần trăm',
  FIXED_AMOUNT: 'Giảm số tiền cố định',
};

export const AVAILABILITY_LABELS: Readonly<Record<CouponAvailability, string>> = {
  RUNNING: 'Đang chạy',
  SCHEDULED: 'Chưa tới ngày',
  EXPIRED: 'Hết hạn',
  EXHAUSTED: 'Hết lượt',
  PAUSED: 'Tạm dừng',
  ARCHIVED: 'Đã lưu trữ',
};

/** One discount code, as the screen renders it. */
export interface Coupon {
  readonly id: string;
  readonly code: string;
  readonly description: string | null;
  readonly discountType: DiscountType;
  /** Percent (1-100) or integer đồng, according to `discountType`. */
  readonly discountValue: number;
  /** Ceiling for a percentage, in đồng. Null means uncapped. */
  readonly maxDiscount: number | null;
  /** Integer đồng; 0 means no minimum. */
  readonly minOrder: number;
  /** Null means unlimited. */
  readonly usageLimit: number | null;
  readonly redeemedCount: number;
  readonly remainingUses: number | null;
  /** ISO instants. The server stores UTC; the browser renders local time. */
  readonly startsAt: string | null;
  readonly endsAt: string | null;
  readonly status: CouponStatus;
  readonly availability: CouponAvailability;
}

/** What the form produced, before the server has judged it. */
export interface CouponDraft {
  readonly code: string;
  readonly description: string | null;
  readonly discountType: DiscountType;
  readonly discountValue: number;
  readonly maxDiscount: number | null;
  readonly minOrder: number;
  readonly usageLimit: number | null;
  readonly startsAt: string | null;
  readonly endsAt: string | null;
  readonly status: CouponStatus;
}

interface Money {
  readonly amount: string;
  readonly currency: string;
}

interface ApiCoupon {
  readonly id: string;
  readonly code: string;
  readonly description: string | null;
  readonly discount_type: string;
  readonly discount_value: number;
  readonly max_discount_amount: Money | null;
  readonly min_order_amount: Money;
  readonly usage_limit: number | null;
  readonly redeemed_count: number;
  readonly remaining_uses: number | null;
  readonly starts_at: string | null;
  readonly ends_at: string | null;
  readonly status: string;
  readonly availability: string;
}

interface ApiCouponPage {
  readonly items: readonly ApiCoupon[];
  readonly next_cursor: string | null;
  readonly has_more: boolean;
}

/** Integer đồng from the transport string (CLAUDE.md section 6). */
function toAmount(value: Money | null): number | null {
  return value === null ? null : Number.parseInt(value.amount, 10);
}

/** Narrowed through the known list rather than cast. */
function toStatus(value: string): CouponStatus {
  return COUPON_STATUSES.find((status) => status === value) ?? 'ARCHIVED';
}

function toDiscountType(value: string): DiscountType {
  return DISCOUNT_TYPES.find((type) => type === value) ?? 'FIXED_AMOUNT';
}

function toAvailability(value: string): CouponAvailability {
  return COUPON_AVAILABILITIES.find((state) => state === value) ?? 'ARCHIVED';
}

function toCoupon(coupon: ApiCoupon): Coupon {
  return {
    id: coupon.id,
    code: coupon.code,
    description: coupon.description,
    discountType: toDiscountType(coupon.discount_type),
    discountValue: coupon.discount_value,
    maxDiscount: toAmount(coupon.max_discount_amount),
    minOrder: toAmount(coupon.min_order_amount) ?? 0,
    usageLimit: coupon.usage_limit,
    redeemedCount: coupon.redeemed_count,
    remainingUses: coupon.remaining_uses,
    startsAt: coupon.starts_at,
    endsAt: coupon.ends_at,
    status: toStatus(coupon.status),
    availability: toAvailability(coupon.availability),
  };
}

function toPayload(draft: CouponDraft) {
  return {
    code: draft.code,
    description: draft.description,
    discount_type: draft.discountType,
    discount_value: draft.discountValue,
    // Only a percentage carries a ceiling; the API refuses one otherwise.
    max_discount_amount: draft.discountType === 'PERCENTAGE' ? draft.maxDiscount : null,
    min_order_amount: draft.minOrder,
    usage_limit: draft.usageLimit,
    starts_at: draft.startsAt,
    ends_at: draft.endsAt,
    status: draft.status,
  };
}

/**
 * Every code of the store, newest first.
 *
 * The store comes from the host the request arrives on, never from the
 * client, so there is no store id to pass (CLAUDE.md section 8).
 */
export async function loadCoupons(): Promise<readonly Coupon[]> {
  const page = await api.get<ApiCouponPage>('/admin/coupons');

  return page.items.map(toCoupon);
}

export async function createCoupon(draft: CouponDraft): Promise<Coupon> {
  return toCoupon(await api.post<ApiCoupon>('/admin/coupons', toPayload(draft)));
}

/**
 * Saves an edit.
 *
 * The code goes along unchanged: the server refuses a different one rather
 * than renaming a code that is already on a sign by the door.
 */
export async function updateCoupon(couponId: string, draft: CouponDraft): Promise<Coupon> {
  return toCoupon(await api.put<ApiCoupon>(`/admin/coupons/${couponId}`, toPayload(draft)));
}

/** "Giảm 10% · tối đa 20.000đ · đơn từ 100.000đ" - the rule in one line. */
export function describeCoupon(coupon: Coupon): string {
  const parts: string[] = [
    coupon.discountType === 'PERCENTAGE'
      ? `Giảm ${coupon.discountValue}%`
      : `Giảm ${money(coupon.discountValue)}`,
  ];

  if (coupon.discountType === 'PERCENTAGE' && coupon.maxDiscount !== null) {
    parts.push(`tối đa ${money(coupon.maxDiscount)}`);
  }

  if (coupon.minOrder > 0) {
    parts.push(`đơn từ ${money(coupon.minOrder)}`);
  }

  return parts.join(' · ');
}

/** "18/100 lượt" or "18 lượt" when the code is unlimited. */
export function describeUsage(coupon: Coupon): string {
  return coupon.usageLimit === null
    ? `${coupon.redeemedCount} lượt`
    : `${coupon.redeemedCount}/${coupon.usageLimit} lượt`;
}

/** The CSS tone for an availability badge; see StatusBadge for the palette. */
export function availabilityTone(availability: CouponAvailability): string {
  switch (availability) {
    case 'RUNNING':
      return 'ready';
    case 'SCHEDULED':
      return 'waiting';
    case 'PAUSED':
      return 'preparing';
    case 'ARCHIVED':
      return 'done';
    default:
      return 'cancelled';
  }
}

/** "20/11/2026", or a dash when the window is open-ended. */
export function dateLabel(iso: string | null): string {
  if (iso === null) {
    return '—';
  }

  const at = new Date(iso);

  return Number.isNaN(at.getTime()) ? '—' : at.toLocaleDateString('vi-VN');
}

/**
 * An ISO instant from the `yyyy-mm-dd` an input[type=date] produces.
 *
 * A date has no time, so a start becomes the first instant of that day and an
 * end becomes the first instant of the NEXT day: a campaign that runs "to the
 * 30th" includes the 30th, and the server's `now >= ends_at` test then expires
 * it at midnight rather than at breakfast.
 */
export function toInstant(date: string, bound: 'start' | 'end'): string | null {
  if (date.trim() === '') {
    return null;
  }

  const at = new Date(`${date}T00:00:00`);

  if (Number.isNaN(at.getTime())) {
    return null;
  }

  if (bound === 'end') {
    at.setDate(at.getDate() + 1);
  }

  return at.toISOString();
}

/** The `yyyy-mm-dd` an input[type=date] wants, from a stored instant. */
export function toDateInput(iso: string | null, bound: 'start' | 'end'): string {
  if (iso === null) {
    return '';
  }

  const at = new Date(iso);

  if (Number.isNaN(at.getTime())) {
    return '';
  }

  // The stored end is the first instant of the day after; show the last day
  // the code actually works, which is what the operator typed.
  if (bound === 'end') {
    at.setDate(at.getDate() - 1);
  }

  const month = String(at.getMonth() + 1).padStart(2, '0');
  const day = String(at.getDate()).padStart(2, '0');

  return `${at.getFullYear()}-${month}-${day}`;
}
