<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\DTO;

use App\Modules\Payment\Domain\Enum\DiscrepancyType;
use App\Shared\Domain\ValueObject\Money;

final readonly class FlagPaymentInput
{
    public function __construct(
        public DiscrepancyType $type,
        public ?Money $observedAmount,
        public ?string $note,
    ) {}
}
