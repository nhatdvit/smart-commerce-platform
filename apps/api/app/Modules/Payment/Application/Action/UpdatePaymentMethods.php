<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Action;

use App\Modules\Payment\Domain\Contract\BankAccountRepository;
use App\Modules\Payment\Domain\Contract\PaymentMethodRepository;
use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Enables or disables the two MVP payment methods for a store.
 *
 * Two invariants, both refused rather than silently corrected:
 *
 *   - leaving a store with neither method enabled, because a store that
 *     accepts neither cash nor transfer cannot take an order at all;
 *   - enabling `BANK_TRANSFER` with no bank account configured, because the
 *     result would be a method a customer can choose that generates nothing
 *     to pay against.
 */
final readonly class UpdatePaymentMethods
{
    public function __construct(
        private PaymentMethodRepository $methods,
        private BankAccountRepository $bankAccounts,
        private RecordsAuditTrail $audit,
    ) {}

    /**
     * @param  array<string, bool>  $enabled  PaymentMethod value => enabled
     * @return array<string, bool>
     */
    public function handle(string $storeId, array $enabled, string $actorId): array
    {
        if (! array_filter($enabled)) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A store must keep at least one payment method enabled',
                [['field' => 'items', 'message' => 'Phải bật ít nhất một phương thức thanh toán.']],
            );
        }

        if (($enabled[PaymentMethod::BankTransfer->value] ?? false)
            && $this->bankAccounts->findDefaultInStore($storeId) === null
        ) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'Bank transfer cannot be enabled without a configured bank account',
                [[
                    'field' => 'items',
                    'message' => 'Cần cấu hình tài khoản ngân hàng trước khi bật chuyển khoản.',
                ]],
            );
        }

        return DB::transaction(function () use ($storeId, $enabled, $actorId): array {
            $before = $this->methods->forStore($storeId);
            $after = $this->methods->setForStore($storeId, $enabled);

            $this->audit->record(
                AuditEntry::for('payment.methods.update', 'store', $storeId, [
                    'before' => $before,
                    'after' => $after,
                ])->inStore($storeId)->by($actorId),
            );

            return $after;
        });
    }
}
