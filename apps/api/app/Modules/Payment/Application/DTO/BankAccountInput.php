<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\DTO;

/**
 * What the operator typed. `bankName` is deliberately absent: the designed
 * contract (`UpdateStoreBankAccountRequest`) takes only the BIN, and the name
 * shown everywhere after is resolved from it by
 * {@see \App\Modules\Payment\Domain\ValueObject\KnownBank} - never typed by
 * hand, so it cannot drift from what the BIN actually identifies.
 */
final readonly class BankAccountInput
{
    public function __construct(
        public string $bankBin,
        public string $accountNumber,
        public string $accountName,
        public bool $isDefault,
    ) {}
}
