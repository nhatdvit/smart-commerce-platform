<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Action;

use App\Modules\Payment\Application\DTO\BankAccountInput;
use App\Modules\Payment\Domain\Contract\BankAccountRepository;
use App\Modules\Payment\Domain\Entity\BankAccount;
use App\Modules\Payment\Domain\ValueObject\KnownBank;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Sets where a store's bank transfers are received.
 *
 * Every change is audited with the actor, the previous value and the new one
 * (CLAUDE.md section 6) - the account number masked the same way a read of it
 * is, because an audit log is itself a long-lived store of exactly the data a
 * masked read exists to keep out of screenshots and support tickets.
 *
 * Customers never reach this: the route it serves requires
 * `payment.bank-account.manage`, an owner-level grant `STORE_MANAGER` does not
 * hold (see RolePermissionSeeder).
 */
final readonly class UpdateBankAccount
{
    public function __construct(
        private BankAccountRepository $accounts,
        private RecordsAuditTrail $audit,
    ) {}

    public function handle(string $storeId, BankAccountInput $input, string $actorId): BankAccount
    {
        $bankName = KnownBank::nameFor($input->bankBin);

        if ($bankName === null) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'This bank identifier is not in the known VietQR participant list',
                [['field' => 'bank_bin', 'message' => 'Mã ngân hàng không nằm trong danh sách NAPAS/VietQR đã biết.']],
            );
        }

        return DB::transaction(function () use ($storeId, $input, $actorId, $bankName): BankAccount {
            $existing = $this->accounts->findDefaultInStore($storeId);

            $account = BankAccount::create(
                null,
                $storeId,
                $input->bankBin,
                $bankName,
                $input->accountNumber,
                $input->accountName,
                $input->isDefault,
                true,
                $actorId,
                now()->toImmutable(),
            );

            $saved = $this->accounts->setDefault($account);

            $this->audit->record(
                AuditEntry::for('payment.bank-account.update', 'store_bank_account', (string) $saved->id, [
                    'bank_bin' => ['from' => $existing?->bankBin, 'to' => $saved->bankBin],
                    // Masked on the way in too: SensitiveDataRedactor would
                    // mask it anyway, but the record should never pretend to
                    // hold the real value even before redaction runs.
                    'account_number' => ['from' => $existing?->maskedAccountNumber(), 'to' => $saved->maskedAccountNumber()],
                    'account_name' => ['from' => $existing?->accountName, 'to' => $saved->accountName],
                ])->inStore($storeId)->by($actorId),
            );

            return $saved;
        });
    }
}
