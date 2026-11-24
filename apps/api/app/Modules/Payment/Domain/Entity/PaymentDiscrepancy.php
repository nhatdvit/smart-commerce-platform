<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Entity;

use App\Modules\Payment\Domain\Enum\DiscrepancyType;
use App\Modules\Payment\Domain\Enum\OverpaymentDisposition;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;

/**
 * A recorded difference between what arrived and what was owed.
 *
 * Kept as DATA, never resolved automatically: a wrong reference, a duplicate
 * or a late transfer are business decisions, and CLAUDE.md section 15 has not
 * settled a general refund policy for them. The one exception is `$disposition`
 * - set only for {@see DiscrepancyType::Overpaid}, where section 6's dated
 * decision requires the operator to say what becomes of the excess at
 * confirmation time, rather than leaving it as an unexplained number.
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
        public ?OverpaymentDisposition $disposition = null,
    ) {}
}
