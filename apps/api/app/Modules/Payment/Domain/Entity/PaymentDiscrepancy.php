<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Entity;

use App\Modules\Payment\Domain\Enum\DiscrepancyType;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;

/**
 * A recorded difference between what arrived and what was owed.
 *
 * Kept as DATA, never resolved automatically: under- and overpayment, a wrong
 * reference or a late transfer are business decisions, and CLAUDE.md section
 * 15 has not settled the refund policy. This record is the whole of what the
 * system does about it - it documents the exception so the payment stays in
 * the queue with an explanation, instead of being closed to make the list
 * shorter.
 */
final readonly class PaymentDiscrepancy
{
    public function __construct(
        public DiscrepancyType $type,
        public ?Money $expectedAmount,
        public ?Money $observedAmount,
        public ?string $note,
        public ?string $recordedBy,
        public ?string $recordedByName,
        public ?DateTimeImmutable $recordedAt,
    ) {}
}
