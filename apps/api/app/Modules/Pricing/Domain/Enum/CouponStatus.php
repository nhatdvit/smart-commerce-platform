<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Enum;

/**
 * What the operator decided about a code.
 *
 * Intent only. Whether a code can be used right now also depends on its dates
 * and on how many times it has been redeemed - that is `CouponAvailability`,
 * derived at read time rather than stored, so a clock tick can never leave a
 * stored status lying.
 */
enum CouponStatus: string
{
    case Active = 'ACTIVE';

    /** Switched off by hand, keeping its dates and counters intact. */
    case Paused = 'PAUSED';

    /** Retired. Kept because orders that used it must stay readable. */
    case Archived = 'ARCHIVED';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Đang bật',
            self::Paused => 'Tạm dừng',
            self::Archived => 'Đã lưu trữ',
        };
    }
}
