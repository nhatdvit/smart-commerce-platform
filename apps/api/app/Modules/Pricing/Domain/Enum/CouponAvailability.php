<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Enum;

/**
 * Whether a code works right now, and if not, why not.
 *
 * Derived, never stored. A coupon that expires at 10:00 becomes `Expired` at
 * 10:00 whether or not anything wrote a row, and a stored status would simply
 * be wrong until a job got round to it. The five reasons are kept apart
 * because they are different conversations with a customer: "that ended
 * yesterday" is not "we have run out of those".
 */
enum CouponAvailability: string
{
    case Running = 'RUNNING';
    case Scheduled = 'SCHEDULED';
    case Expired = 'EXPIRED';
    case Exhausted = 'EXHAUSTED';
    case Paused = 'PAUSED';
    case Archived = 'ARCHIVED';

    public function isUsable(): bool
    {
        return $this === self::Running;
    }

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Đang chạy',
            self::Scheduled => 'Chưa tới ngày',
            self::Expired => 'Hết hạn',
            self::Exhausted => 'Hết lượt',
            self::Paused => 'Tạm dừng',
            self::Archived => 'Đã lưu trữ',
        };
    }
}
