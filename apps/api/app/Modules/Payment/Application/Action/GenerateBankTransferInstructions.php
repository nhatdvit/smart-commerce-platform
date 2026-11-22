<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Action;

use App\Modules\Payment\Domain\Contract\BankAccountRepository;
use App\Modules\Payment\Domain\Contract\PaymentRepository;
use App\Modules\Payment\Domain\Entity\Payment;
use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Modules\Payment\Domain\ValueObject\TransferReference;
use App\Modules\Payment\Domain\ValueObject\VietQrPayload;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Domain\ValueObject\Money;
use App\Shared\Presentation\Http\ErrorCode;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds the dynamic VietQR a customer pays a bank-transfer order against.
 *
 * The step CLAUDE.md section 6 places right after order creation: "generate a
 * dynamic VietQR using the store's configured bank identifier, receiving
 * account, exact VND amount, and unique transfer reference derived from the
 * order code." There is no checkout to call this from yet, so its first-time
 * caller today is the demo seeder; it is written as the reusable use case
 * checkout will call once it exists (CLAUDE.md section 3: expose use cases
 * independent of HTTP).
 *
 * The same action also serves a **regenerate**: an operator replacing an
 * expired or lost QR with a fresh reference and a fresh window, from
 * `AdminPaymentController::regenerateQr`. `$expiresAt` and `$actorId` are only
 * ever both given together, by that one caller - the seeder passes neither,
 * so a brand-new payment's own `expires_at` (set once, by whatever created
 * the row) is left exactly as it was, and no audit entry is written for data
 * that was only ever seeded, not operated on.
 *
 * Showing this is never payment. Nothing this action does changes a payment's
 * status - it only attaches instructions to one still unsettled.
 */
final readonly class GenerateBankTransferInstructions
{
    /** Bounded: a stuck loop is a bug, not a retry strategy. */
    private const int MAX_ATTEMPTS = 5;

    public function __construct(
        private PaymentRepository $payments,
        private BankAccountRepository $bankAccounts,
        private RecordsAuditTrail $audit,
        private string $referencePrefix,
    ) {}

    public function handle(
        string $storeId,
        string $paymentId,
        string $orderCode,
        Money $amount,
        ?DateTimeImmutable $expiresAt = null,
        ?string $actorId = null,
    ): Payment {
        return DB::transaction(function () use ($storeId, $paymentId, $orderCode, $amount, $expiresAt, $actorId): Payment {
            // Locked for the rest of the transaction: a regenerate racing a
            // confirmation must see the committed result of whichever one
            // actually happened, never a stale PENDING read.
            $existing = $this->payments->findForUpdate($storeId, $paymentId);

            if ($existing === null) {
                throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Payment not found');
            }

            if ($existing->method !== PaymentMethod::BankTransfer) {
                throw new BusinessRuleViolation(
                    ErrorCode::InvalidStateTransition,
                    'Only a bank-transfer payment can have VietQR instructions generated',
                    [['field' => 'method', 'message' => 'Chỉ áp dụng cho thanh toán chuyển khoản.']],
                );
            }

            if ($existing->status->isSettled()) {
                throw new BusinessRuleViolation(
                    ErrorCode::PaymentAlreadyConfirmed,
                    sprintf('Payment is already %s', $existing->status->value),
                    [[
                        'field' => 'status',
                        'message' => sprintf('Thanh toán này đã ở trạng thái "%s".', $existing->status->label()),
                    ]],
                );
            }

            $account = $this->bankAccounts->findDefaultInStore($storeId);

            if ($account === null) {
                // Enabling BANK_TRANSFER with no account configured is already
                // refused at the store-settings endpoint; reaching this with
                // none configured means that rule was bypassed somewhere,
                // which is a configuration fault, not a customer-facing 422.
                throw new BusinessRuleViolation(
                    ErrorCode::InvalidStoreContext,
                    'This store has no active bank account configured for transfers',
                );
            }

            $reference = $this->uniqueReference($orderCode);

            $payload = VietQrPayload::build(
                $account->bankBin,
                $account->accountNumber,
                $account->accountName,
                $amount,
                $reference,
            );

            $saved = $this->payments->attachBankTransferInstructions(
                $storeId,
                $paymentId,
                (string) $account->id,
                $account->bankBin,
                $account->accountNumber,
                $account->accountName,
                $reference->value,
                $payload->value,
                $expiresAt,
            );

            if ($actorId !== null) {
                $this->audit->record(
                    AuditEntry::for('payment.transfer-instructions.regenerate', 'payment', $paymentId, [
                        'order_code' => $orderCode,
                        'previous_transfer_reference' => $existing->transferReference,
                        'transfer_reference' => $reference->value,
                        'expires_at' => $expiresAt?->format(DateTimeImmutable::ATOM),
                    ])->inStore($storeId)->by($actorId),
                );
            }

            return $saved;
        });
    }

    /**
     * A transfer reference is unique platform-wide (the column carries the
     * constraint), so collisions are rare but not impossible - two orders
     * placed in the same millisecond can derive the same code from similar
     * order sequences. Retried with a fresh random disambiguator each time,
     * bounded so a persistent fault surfaces as an error instead of a hang.
     */
    private function uniqueReference(string $orderCode): TransferReference
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $disambiguator = $attempt === 0 ? '' : strtoupper(Str::random(4));
            $candidate = TransferReference::fromOrderCode($this->referencePrefix, $orderCode, $disambiguator);

            if (! $this->payments->transferReferenceExists($candidate->value)) {
                return $candidate;
            }
        }

        throw new BusinessRuleViolation(
            ErrorCode::InternalError,
            'Could not generate a unique transfer reference after several attempts',
        );
    }
}
