<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Action;

use App\Modules\Payment\Domain\Contract\BankAccountRepository;
use App\Modules\Payment\Domain\Contract\PaymentRepository;
use App\Modules\Payment\Domain\Entity\Payment;
use App\Modules\Payment\Domain\ValueObject\TransferReference;
use App\Modules\Payment\Domain\ValueObject\VietQrPayload;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Domain\ValueObject\Money;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Support\Str;

/**
 * Builds the dynamic VietQR a customer pays a bank-transfer order against.
 *
 * The step CLAUDE.md section 6 places right after order creation: "generate a
 * dynamic VietQR using the store's configured bank identifier, receiving
 * account, exact VND amount, and unique transfer reference derived from the
 * order code." There is no checkout to call this from yet, so today its only
 * caller is the demo seeder; it is written as the reusable use case checkout
 * will call once it exists (CLAUDE.md section 3: expose use cases independent
 * of HTTP).
 *
 * Showing this is never payment. Nothing this action does changes a payment's
 * status - it only attaches instructions to one still `PENDING`.
 */
final readonly class GenerateBankTransferInstructions
{
    /** Bounded: a stuck loop is a bug, not a retry strategy. */
    private const int MAX_ATTEMPTS = 5;

    public function __construct(
        private PaymentRepository $payments,
        private BankAccountRepository $bankAccounts,
        private string $referencePrefix,
    ) {}

    public function handle(string $storeId, string $paymentId, string $orderCode, Money $amount): Payment
    {
        $account = $this->bankAccounts->findDefaultInStore($storeId);

        if ($account === null) {
            // Enabling BANK_TRANSFER with no account configured is already
            // refused at the store-settings endpoint; reaching this with none
            // configured means that rule was bypassed somewhere, which is a
            // configuration fault, not a customer-facing 422.
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

        return $this->payments->attachBankTransferInstructions(
            $storeId,
            $paymentId,
            (string) $account->id,
            $account->bankBin,
            $account->accountNumber,
            $account->accountName,
            $reference->value,
            $payload->value,
        );
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
