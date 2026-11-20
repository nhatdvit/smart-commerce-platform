<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enum;

/**
 * How an order is paid. Fixed MVP scope, decided 2026-11-09.
 *
 * Cash collected at handover, or a bank transfer against a dynamically
 * generated VietQR that authorized staff verify by hand. No gateway, no card
 * processing, no automatic reconciliation without a separately approved
 * requirement (CLAUDE.md section 6) - which is why this enum is deliberately
 * not open-ended.
 */
enum PaymentMethod: string
{
    case Cash = 'CASH';
    case BankTransfer = 'BANK_TRANSFER';

    /** Whether the money is expected before the order is served. */
    public function isPrepaid(): bool
    {
        return $this === self::BankTransfer;
    }

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tiền mặt',
            self::BankTransfer => 'Chuyển khoản',
        };
    }
}
