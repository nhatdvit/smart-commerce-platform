<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Entity;

use App\Modules\Pricing\Domain\Enum\CouponAvailability;
use App\Modules\Pricing\Domain\Enum\CouponStatus;
use App\Modules\Pricing\Domain\Enum\DiscountType;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Domain\ValueObject\Money;
use App\Shared\Presentation\Http\ErrorCode;
use DateTimeImmutable;

/**
 * A discount code a store hands out.
 *
 * The rules here are the ones that stop a coupon from costing a shop money it
 * never meant to spend: a percentage that is actually a percentage, a fixed
 * amount no larger than the order it requires, a cap on what a percentage can
 * take off. Every one of them is also a CHECK constraint on `coupons`, so a
 * seeder or a future service cannot write what this class refuses.
 *
 * Immutable, and framework-free (CLAUDE.md sections 3 and 16).
 */
final readonly class Coupon
{
    /** What a customer can read out over a counter without ambiguity. */
    private const string CODE_PATTERN = '/^[A-Z0-9_-]{3,32}$/';

    private function __construct(
        public ?string $id,
        public string $storeId,
        public string $code,
        public ?string $description,
        public DiscountType $discountType,
        /** Percent (1-100) or whole dong, according to `discountType`. */
        public int $discountValue,
        /** Ceiling for a percentage. Null means uncapped; never set for a fixed amount. */
        public ?Money $maxDiscount,
        public Money $minOrder,
        /** Null means unlimited. */
        public ?int $usageLimit,
        public int $redeemedCount,
        public ?DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public CouponStatus $status,
        public ?DateTimeImmutable $createdAt,
    ) {}

    public static function create(
        ?string $id,
        string $storeId,
        string $code,
        ?string $description,
        DiscountType $discountType,
        int $discountValue,
        ?Money $maxDiscount,
        ?Money $minOrder,
        ?int $usageLimit,
        int $redeemedCount,
        ?DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
        CouponStatus $status,
        ?DateTimeImmutable $createdAt = null,
    ): self {
        $code = self::normaliseCode($code);
        $minOrder ??= Money::zero();
        $description = $description === null ? null : trim($description);

        self::assertDiscountMakesSense($discountType, $discountValue, $maxDiscount, $minOrder);
        self::assertLimitsMakeSense($usageLimit, $redeemedCount);
        self::assertWindowMakesSense($startsAt, $endsAt);

        return new self(
            $id,
            $storeId,
            $code,
            $description === '' ? null : $description,
            $discountType,
            $discountValue,
            $maxDiscount,
            $minOrder,
            $usageLimit,
            $redeemedCount,
            $startsAt,
            $endsAt,
            $status,
            $createdAt,
        );
    }

    /**
     * Whether the code works right now, and if not, why not.
     *
     * The order of the checks is the order a staff member would explain it in:
     * what the shop decided comes before what the calendar says, and both come
     * before "we have run out". Derived rather than stored, so a coupon that
     * ends at 10:00 is expired at 10:00 without anything having to run.
     */
    public function availabilityAt(DateTimeImmutable $now): CouponAvailability
    {
        if ($this->status === CouponStatus::Archived) {
            return CouponAvailability::Archived;
        }

        if ($this->status === CouponStatus::Paused) {
            return CouponAvailability::Paused;
        }

        if ($this->startsAt !== null && $now < $this->startsAt) {
            return CouponAvailability::Scheduled;
        }

        if ($this->endsAt !== null && $now >= $this->endsAt) {
            return CouponAvailability::Expired;
        }

        if ($this->usageLimit !== null && $this->redeemedCount >= $this->usageLimit) {
            return CouponAvailability::Exhausted;
        }

        return CouponAvailability::Running;
    }

    /** Redemptions left, or null when the code is unlimited. */
    public function remainingUses(): ?int
    {
        return $this->usageLimit === null
            ? null
            : max(0, $this->usageLimit - $this->redeemedCount);
    }

    /**
     * The same coupon with the operator's edits applied.
     *
     * The code is NOT among them. A code is already in customers' hands and on
     * a sign by the door; changing it silently would strand everyone holding
     * the old one. Retiring this coupon and creating another is the honest way
     * to change a code, and it keeps the history of what the old one did.
     */
    public function withEdits(
        ?string $description,
        DiscountType $discountType,
        int $discountValue,
        ?Money $maxDiscount,
        ?Money $minOrder,
        ?int $usageLimit,
        ?DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
        CouponStatus $status,
    ): self {
        return self::create(
            $this->id,
            $this->storeId,
            $this->code,
            $description,
            $discountType,
            $discountValue,
            $maxDiscount,
            $minOrder,
            $usageLimit,
            // Carried over, never accepted from a caller: how many times a
            // code has been used is the system's record, not an editable field.
            $this->redeemedCount,
            $startsAt,
            $endsAt,
            $status,
            $this->createdAt,
        );
    }

    private static function normaliseCode(string $code): string
    {
        $code = strtoupper(trim($code));

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A coupon code must be 3 to 32 characters of A-Z, 0-9, hyphen or underscore',
                [['field' => 'code', 'message' => 'Mã chỉ gồm A-Z, 0-9, gạch ngang hoặc gạch dưới, từ 3 đến 32 ký tự.']],
            );
        }

        return $code;
    }

    private static function assertDiscountMakesSense(
        DiscountType $type,
        int $value,
        ?Money $maxDiscount,
        Money $minOrder,
    ): void {
        if ($value <= 0) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A coupon must take something off',
                [['field' => 'discount_value', 'message' => 'Mức giảm phải lớn hơn 0.']],
            );
        }

        if ($minOrder->isNegative()) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A minimum order cannot be negative',
                [['field' => 'min_order_amount', 'message' => 'Giá trị đơn tối thiểu không được âm.']],
            );
        }

        if ($type === DiscountType::Percentage && $value > 100) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A percentage discount cannot exceed 100',
                [['field' => 'discount_value', 'message' => 'Phần trăm giảm tối đa là 100.']],
            );
        }

        if ($maxDiscount !== null && ! $type->acceptsCap()) {
            // A ceiling on a fixed amount is either the amount itself or a
            // contradiction; refusing it keeps the pair readable.
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'Only a percentage discount can carry a ceiling',
                [['field' => 'max_discount_amount', 'message' => 'Chỉ mã giảm theo phần trăm mới cần mức giảm tối đa.']],
            );
        }

        if ($maxDiscount !== null && ($maxDiscount->isNegative() || $maxDiscount->isZero())) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A discount ceiling must be greater than zero',
                [['field' => 'max_discount_amount', 'message' => 'Mức giảm tối đa phải lớn hơn 0.']],
            );
        }

        // A fixed discount bigger than the order it requires means the shop
        // pays the customer to order breakfast.
        if ($type === DiscountType::FixedAmount
            && ! $minOrder->isZero()
            && $value > $minOrder->amount
        ) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A fixed discount cannot exceed the minimum order it requires',
                [[
                    'field' => 'discount_value',
                    'message' => 'Số tiền giảm không được lớn hơn giá trị đơn tối thiểu.',
                ]],
            );
        }
    }

    private static function assertLimitsMakeSense(?int $usageLimit, int $redeemedCount): void
    {
        if ($redeemedCount < 0) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A redemption count cannot be negative',
                [['field' => 'usage_limit', 'message' => 'Số lượt đã dùng không hợp lệ.']],
            );
        }

        if ($usageLimit === null) {
            return;
        }

        if ($usageLimit <= 0) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A usage limit must be at least one',
                [['field' => 'usage_limit', 'message' => 'Giới hạn lượt dùng phải từ 1 trở lên; để trống nghĩa là không giới hạn.']],
            );
        }

        // Lowering the limit under what has already been handed out would make
        // the stored counter a lie, so it is refused rather than clamped.
        if ($usageLimit < $redeemedCount) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A usage limit cannot be lower than the redemptions already recorded',
                [[
                    'field' => 'usage_limit',
                    'message' => sprintf('Mã đã được dùng %d lượt, giới hạn không thể thấp hơn.', $redeemedCount),
                ]],
            );
        }
    }

    private static function assertWindowMakesSense(
        ?DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
    ): void {
        if ($startsAt !== null && $endsAt !== null && $endsAt <= $startsAt) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A coupon window must end after it starts',
                [['field' => 'ends_at', 'message' => 'Ngày kết thúc phải sau ngày bắt đầu.']],
            );
        }
    }
}
