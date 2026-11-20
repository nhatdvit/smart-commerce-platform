<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\DTO;

use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Modules\Payment\Domain\Enum\PaymentStatus;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;

/**
 * What another module is allowed to know about a payment.
 *
 * The Order module needs to show how an order is being paid and whether the
 * money has been seen. It gets that from this exported read model rather than
 * by querying the payments table, which would couple the two modules at the
 * database (CLAUDE.md section 3).
 *
 * Deliberately read-only and deliberately incomplete: the bank account
 * snapshot, the VietQR payload and the transfer reference belong to the
 * payments screen and its manual confirmation flow. Nothing here lets another
 * module decide that money arrived.
 */
final readonly class PaymentSnapshot
{
    public function __construct(
        public string $paymentId,
        public string $orderId,
        public PaymentMethod $method,
        public PaymentStatus $status,
        public Money $amount,
        /** End of the transfer window, for a bank transfer. Null for cash. */
        public ?DateTimeImmutable $expiresAt,
        /** When staff confirmed the real money. Not when a QR was shown. */
        public ?DateTimeImmutable $paidAt,
    ) {}
}
