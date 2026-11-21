<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Enum;

/**
 * What the number on a coupon means.
 *
 * Two kinds, because they are the two a shop actually writes on a sign:
 * "giảm 10%" and "giảm 5.000đ". A percentage may carry a ceiling so a party
 * order does not take an unbounded amount off; a fixed amount is already its
 * own ceiling.
 */
enum DiscountType: string
{
    case Percentage = 'PERCENTAGE';
    case FixedAmount = 'FIXED_AMOUNT';

    /** Only a percentage can be capped; a fixed amount caps itself. */
    public function acceptsCap(): bool
    {
        return $this === self::Percentage;
    }

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Giảm theo phần trăm',
            self::FixedAmount => 'Giảm số tiền cố định',
        };
    }
}
