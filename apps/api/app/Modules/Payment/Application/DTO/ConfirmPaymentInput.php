<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\DTO;

use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;

final readonly class ConfirmPaymentInput
{
    public function __construct(
        public Money $amount,
        public ?string $bankTransactionReference,
        public ?DateTimeImmutable $observedAt,
        public bool $acceptDiscrepancy,
        public ?string $note,
    ) {}
}
