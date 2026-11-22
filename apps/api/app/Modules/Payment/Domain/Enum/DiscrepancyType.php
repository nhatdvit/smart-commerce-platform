<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enum;

/**
 * What kind of exception a settlement produced.
 *
 * Seven cases because they are seven different conversations with a customer,
 * not seven degrees of the same problem: "that arrived late" is not "we got
 * two of those" is not "we cannot tell whose transfer this is". Resolution -
 * refund, manual match, writing it off - is a store policy decision CLAUDE.md
 * section 15 still leaves open, so this enum only names the exception; nothing
 * here automates fixing it.
 */
enum DiscrepancyType: string
{
    case Underpaid = 'UNDERPAID';
    case Overpaid = 'OVERPAID';
    case MissingReference = 'MISSING_REFERENCE';
    case UnknownReference = 'UNKNOWN_REFERENCE';
    case DuplicateTransfer = 'DUPLICATE_TRANSFER';
    case LateTransfer = 'LATE_TRANSFER';
    case CancelledOrderPaid = 'CANCELLED_ORDER_PAID';

    public function label(): string
    {
        return match ($this) {
            self::Underpaid => 'Thiếu tiền',
            self::Overpaid => 'Thừa tiền',
            self::MissingReference => 'Không có mã tham chiếu',
            self::UnknownReference => 'Mã tham chiếu không khớp đơn nào',
            self::DuplicateTransfer => 'Chuyển khoản trùng lặp',
            self::LateTransfer => 'Chuyển khoản đến muộn',
            self::CancelledOrderPaid => 'Đơn đã hủy nhưng có tiền về',
        };
    }
}
