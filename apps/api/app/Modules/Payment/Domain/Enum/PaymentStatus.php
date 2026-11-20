<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enum;

/**
 * The payment lifecycle - a state machine of its own.
 *
 * Independent of the order's status (CLAUDE.md section 6). An order can be
 * COMPLETED with its payment still PENDING: that is an uncollected cash order,
 * and the whole reason the two are never one column.
 *
 * Only authorized staff move a payment to `Paid`, and only after seeing the
 * real money in the bank or in the till. A generated QR, a screenshot or an
 * "I have transferred" click is never proof of funds.
 */
enum PaymentStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Paid = 'PAID';
    case Failed = 'FAILED';
    case PartiallyRefunded = 'PARTIALLY_REFUNDED';
    case Refunded = 'REFUNDED';

    /** Money was seen at some point, so the payment carries a `paid_at`. */
    public function isSettled(): bool
    {
        return match ($this) {
            self::Paid, self::PartiallyRefunded, self::Refunded => true,
            self::Pending, self::Processing, self::Failed => false,
        };
    }

    /** Still owed: the till or the reconciliation queue has work to do. */
    public function isOutstanding(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Chờ thanh toán',
            self::Processing => 'Đang xử lý',
            self::Paid => 'Đã thanh toán',
            self::Failed => 'Thất bại',
            self::PartiallyRefunded => 'Hoàn một phần',
            self::Refunded => 'Đã hoàn tiền',
        };
    }
}
