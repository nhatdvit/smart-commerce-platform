<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence;

use App\Modules\Payment\Domain\Contract\BankAccountRepository;
use App\Modules\Payment\Domain\Entity\BankAccount;
use App\Modules\Payment\Infrastructure\Persistence\Model\BankAccount as BankAccountModel;

/**
 * Eloquent implementation of the bank account seam.
 *
 * `setDefault()` deactivates rather than deletes the previous default - see
 * the contract's own note: a payment may have snapshotted it already, and that
 * snapshot, not this row, is what a customer's QR was generated against.
 */
final class EloquentBankAccountRepository implements BankAccountRepository
{
    public function findDefaultInStore(string $storeId): ?BankAccount
    {
        $row = BankAccountModel::query()
            ->where('store_id', $storeId)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        return $row === null ? null : $this->toEntity($row);
    }

    public function findInStore(string $storeId, string $accountId): ?BankAccount
    {
        $row = BankAccountModel::query()
            ->where('store_id', $storeId)
            ->whereKey($accountId)
            ->first();

        return $row === null ? null : $this->toEntity($row);
    }

    public function setDefault(BankAccount $account): BankAccount
    {
        if ($account->isDefault) {
            // Only one row may be the active default (the partial unique
            // index enforces it); flipping the old one off first is what lets
            // the new insert land without colliding with it.
            BankAccountModel::query()
                ->where('store_id', $account->storeId)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $row = BankAccountModel::query()->create([
            'store_id' => $account->storeId,
            'bank_bin' => $account->bankBin,
            'bank_name' => $account->bankName,
            'account_number' => $account->accountNumber,
            'account_name' => $account->accountName,
            'is_default' => $account->isDefault,
            'is_active' => $account->isActive,
            'updated_by' => $account->updatedBy,
        ]);

        return $this->toEntity($row->refresh());
    }

    private function toEntity(BankAccountModel $row): BankAccount
    {
        return BankAccount::create(
            $row->id,
            $row->store_id,
            $row->bank_bin,
            $row->bank_name,
            $row->account_number,
            $row->account_name,
            $row->is_default,
            $row->is_active,
            $row->updated_by,
            $row->updated_at,
        );
    }
}
