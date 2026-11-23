<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enum;

/**
 * What happens to the extra money, when a confirmed amount exceeds what was
 * owed.
 *
 * Unlike the rest of {@see DiscrepancyType}'s resolution, which CLAUDE.md
 * section 15 still leaves open, this one case is a settled store policy
 * (CLAUDE.md section 6, decision dated 2026-11-24): the operator confirming
 * the overpayment must say on the spot what becomes of the excess, because
 * leaving it as an unexplained number in `confirmed_amount` is the thing this
 * enum exists to prevent. Never chosen by the system - `ConfirmPayment`
 * refuses to settle an overpaid payment without one.
 */
enum OverpaymentDisposition: string
{
    /** The store keeps it - a cash round-up or a deliberate tip. */
    case Keep = 'KEEP';

    /** Handed back to the customer immediately, at the counter or by transfer. */
    case Refund = 'REFUND';

    /** Owed back to the customer, but not settled yet - a debt to clear later. */
    case Debt = 'DEBT';

    public function label(): string
    {
        return match ($this) {
            self::Keep => 'Giữ lại số tiền thừa',
            self::Refund => 'Hoàn lại cho khách',
            self::Debt => 'Ghi nhận công nợ',
        };
    }
}
