<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contract;

use App\Modules\Payment\Domain\Entity\BankAccount;

/**
 * Persistence seam for `store_bank_accounts`.
 *
 * Store-scoped throughout (CLAUDE.md section 8). A store has at most one
 * active default account in this milestone - the unique partial index on
 * `(store_id) WHERE is_default AND is_active` is what actually enforces it;
 * this interface is written as if a store could have several, because the
 * domain rule a future multi-account feature wants is "exactly one default",
 * not "exactly one account ever".
 */
interface BankAccountRepository
{
    /** The store's active default account, or null if none is configured. */
    public function findDefaultInStore(string $storeId): ?BankAccount;

    public function findInStore(string $storeId, string $accountId): ?BankAccount;

    /**
     * Replaces the store's default account.
     *
     * An existing default is deactivated rather than deleted - it may be the
     * beneficiary snapshotted on a payment awaiting confirmation, and that
     * payment's instructions must stay exactly what the customer saw
     * (CLAUDE.md section 6).
     */
    public function setDefault(BankAccount $account): BankAccount;
}
