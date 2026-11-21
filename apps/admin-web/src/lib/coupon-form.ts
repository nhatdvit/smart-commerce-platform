/**
 * The coupon dialog's rules, in one place.
 *
 * Kept out of the component for the same reason the product form is: create
 * and edit share one dialog, and two copies of "what is a valid discount"
 * would drift until one of them let something through. These rules mirror the
 * API's, which is the authority - a 422 from the server is still shown as it
 * was written.
 */

import {
  COUPON_STATUSES,
  DISCOUNT_TYPES,
  type Coupon,
  type CouponDraft,
  type CouponStatus,
  type DiscountType,
} from './coupons-api';
import { money } from './money';
import { toDateInput, toInstant } from './coupons-api';

/** Every field as the form holds it: strings, because inputs produce strings. */
export interface CouponFormValues {
  readonly code: string;
  readonly description: string;
  readonly discountType: DiscountType;
  readonly discountValue: string;
  readonly maxDiscount: string;
  readonly minOrder: string;
  readonly usageLimit: string;
  /** `yyyy-mm-dd` from input[type=date]; empty means open-ended. */
  readonly startsOn: string;
  readonly endsOn: string;
  readonly status: CouponStatus;
}

export type CouponFieldErrors = Partial<Record<keyof CouponFormValues, string>>;

export type CouponFormResult =
  | { readonly ok: true; readonly draft: CouponDraft }
  | { readonly ok: false; readonly errors: CouponFieldErrors };

export const MAX_COUPON_CODE_LENGTH = 32;
export const MIN_COUPON_CODE_LENGTH = 3;
export const MAX_COUPON_DESCRIPTION_LENGTH = 255;
/** Matches MAX_AMOUNT in the API's SaveCouponRequest. */
export const MAX_COUPON_AMOUNT = 100_000_000;

export const EMPTY_COUPON_FORM: CouponFormValues = {
  code: '',
  description: '',
  discountType: 'FIXED_AMOUNT',
  discountValue: '',
  maxDiscount: '',
  minOrder: '',
  usageLimit: '',
  startsOn: '',
  endsOn: '',
  status: 'ACTIVE',
};

export function couponFormValues(coupon: Coupon): CouponFormValues {
  return {
    code: coupon.code,
    description: coupon.description ?? '',
    discountType: coupon.discountType,
    discountValue: String(coupon.discountValue),
    maxDiscount: coupon.maxDiscount === null ? '' : String(coupon.maxDiscount),
    minOrder: coupon.minOrder === 0 ? '' : String(coupon.minOrder),
    usageLimit: coupon.usageLimit === null ? '' : String(coupon.usageLimit),
    startsOn: toDateInput(coupon.startsAt, 'start'),
    endsOn: toDateInput(coupon.endsAt, 'end'),
    status: coupon.status,
  };
}

/**
 * Digits only, on purpose.
 *
 * `Number()` reads `''` as 0, `'1e3'` as 1000 and `'12.00'` as 12, and
 * `Number.isInteger` waves all three through - which is how an empty price
 * field once saved a price of 0đ (see product-form.ts).
 */
function parseWholeNumber(raw: string): number | undefined {
  const trimmed = raw.trim();

  return /^\d{1,9}$/.test(trimmed) ? Number(trimmed) : undefined;
}

function isBlank(raw: string): boolean {
  return raw.trim() === '';
}

/** Validates the form and builds the draft the API expects. */
export function validateCouponForm(values: CouponFormValues): CouponFormResult {
  const errors: CouponFieldErrors = {};

  const code = values.code.trim().toUpperCase();
  const description = values.description.trim();

  if (code === '') {
    errors.code = 'Vui lòng nhập mã ưu đãi.';
  } else if (!/^[A-Z0-9_-]+$/.test(code)) {
    errors.code = 'Mã chỉ gồm chữ không dấu, số, gạch ngang hoặc gạch dưới.';
  } else if (code.length < MIN_COUPON_CODE_LENGTH) {
    errors.code = `Mã phải từ ${MIN_COUPON_CODE_LENGTH} ký tự trở lên.`;
  } else if (code.length > MAX_COUPON_CODE_LENGTH) {
    errors.code = `Mã tối đa ${MAX_COUPON_CODE_LENGTH} ký tự.`;
  }

  if (description.length > MAX_COUPON_DESCRIPTION_LENGTH) {
    errors.description = `Mô tả tối đa ${MAX_COUPON_DESCRIPTION_LENGTH} ký tự.`;
  }

  const isPercentage = values.discountType === 'PERCENTAGE';
  const discountValue = parseWholeNumber(values.discountValue);

  if (discountValue === undefined || discountValue < 1) {
    errors.discountValue = isPercentage
      ? 'Phần trăm giảm phải là số nguyên từ 1.'
      : 'Số tiền giảm phải là số nguyên đồng, lớn hơn 0.';
  } else if (isPercentage && discountValue > 100) {
    errors.discountValue = 'Phần trăm giảm tối đa là 100.';
  } else if (!isPercentage && discountValue > MAX_COUPON_AMOUNT) {
    errors.discountValue = `Số tiền giảm tối đa ${money(MAX_COUPON_AMOUNT)}.`;
  }

  // A ceiling only means something for a percentage; the form hides the field
  // otherwise, and whatever was typed before switching is dropped.
  let maxDiscount: number | null = null;

  if (isPercentage && !isBlank(values.maxDiscount)) {
    const parsed = parseWholeNumber(values.maxDiscount);

    if (parsed === undefined || parsed < 1) {
      errors.maxDiscount = 'Mức giảm tối đa phải là số nguyên đồng, lớn hơn 0.';
    } else if (parsed > MAX_COUPON_AMOUNT) {
      errors.maxDiscount = `Mức giảm tối đa không quá ${money(MAX_COUPON_AMOUNT)}.`;
    } else {
      maxDiscount = parsed;
    }
  }

  let minOrder = 0;

  if (!isBlank(values.minOrder)) {
    const parsed = parseWholeNumber(values.minOrder);

    if (parsed === undefined) {
      errors.minOrder = 'Đơn tối thiểu phải là số nguyên đồng, không âm.';
    } else if (parsed > MAX_COUPON_AMOUNT) {
      errors.minOrder = `Đơn tối thiểu không quá ${money(MAX_COUPON_AMOUNT)}.`;
    } else {
      minOrder = parsed;
    }
  }

  // The rule that keeps a shop from paying its customers to order breakfast.
  // The server enforces it too; saying it here means the operator finds out
  // before they submit.
  if (
    !isPercentage &&
    discountValue !== undefined &&
    minOrder > 0 &&
    discountValue > minOrder &&
    errors.discountValue === undefined
  ) {
    errors.discountValue = 'Số tiền giảm không được lớn hơn đơn tối thiểu.';
  }

  let usageLimit: number | null = null;

  if (!isBlank(values.usageLimit)) {
    const parsed = parseWholeNumber(values.usageLimit);

    if (parsed === undefined || parsed < 1) {
      errors.usageLimit = 'Giới hạn lượt phải từ 1; để trống nghĩa là không giới hạn.';
    } else {
      usageLimit = parsed;
    }
  }

  const startsAt = toInstant(values.startsOn, 'start');
  const endsAt = toInstant(values.endsOn, 'end');

  if (!isBlank(values.startsOn) && startsAt === null) {
    errors.startsOn = 'Ngày bắt đầu không hợp lệ.';
  }

  if (!isBlank(values.endsOn) && endsAt === null) {
    errors.endsOn = 'Ngày kết thúc không hợp lệ.';
  }

  if (startsAt !== null && endsAt !== null && endsAt <= startsAt) {
    errors.endsOn = 'Ngày kết thúc phải từ ngày bắt đầu trở đi.';
  }

  if (Object.keys(errors).length > 0) {
    return { ok: false, errors };
  }

  return {
    ok: true,
    draft: {
      code,
      description: description === '' ? null : description,
      discountType: values.discountType,
      // Checked above; this keeps the type honest without an assertion.
      discountValue: discountValue ?? 0,
      maxDiscount,
      minOrder,
      usageLimit,
      startsAt,
      endsAt,
      status: values.status,
    },
  };
}

/** Re-exported so the dialog imports its options from one place. */
export { COUPON_STATUSES, DISCOUNT_TYPES };
