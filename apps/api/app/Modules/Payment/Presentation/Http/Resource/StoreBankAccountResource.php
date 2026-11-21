<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Resource;

use App\Modules\Payment\Domain\Entity\BankAccount;
use App\Shared\Presentation\Http\IsoTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `StoreBankAccount` schema. Masked on every read - a UI only needs to
 * show which account is configured, and the full number has no business
 * sitting in a screenshot or a support ticket (CLAUDE.md section 6).
 *
 * @property-read BankAccount $resource
 */
final class StoreBankAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $account = $this->resource;

        return [
            'id' => $account->id,
            'bank_bin' => $account->bankBin,
            'bank_name' => $account->bankName,
            'account_number_masked' => $account->maskedAccountNumber(),
            'account_name' => $account->accountName,
            'is_default' => $account->isDefault,
            'is_active' => $account->isActive,
            'updated_at' => IsoTimestamp::of($account->updatedAt),
            'updated_by' => $account->updatedBy,
        ];
    }
}
