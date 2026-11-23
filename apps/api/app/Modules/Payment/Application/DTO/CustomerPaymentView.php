<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\DTO;

use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Modules\Payment\Domain\Enum\PaymentStatus;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;

/**
 * What a customer may see about their own order's payment.
 *
 * Richer than {@see PaymentSnapshot} on purpose: a bank-transfer customer
 * needs the account to pay into and the reference to pay with, which the
 * admin-facing summary deliberately withholds. Still nowhere near the full
 * `Payment` entity - nothing about who confirmed it, mismatches or event
 * history, none of which is this caller's business.
 */
final readonly class CustomerPaymentView
{
    public function __construct(
        public string $paymentId,
        public PaymentMethod $method,
        public PaymentStatus $status,
        public Money $amount,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $paidAt,
        public ?string $transferReference,
        public ?string $qrPayload,
        public ?string $bankBin,
        public ?string $bankName,
        public ?string $bankAccountNumber,
        public ?string $bankAccountName,
    ) {}
}
