<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contract;

use App\Modules\Payment\Domain\Criteria\PaymentCriteria;
use App\Modules\Payment\Domain\Entity\Payment;
use App\Modules\Payment\Domain\Entity\PaymentDiscrepancy;
use DateTimeImmutable;

/**
 * Persistence seam for the admin side of payments: reading the reconciliation
 * queue, and recording a confirmation or an exception.
 *
 * Store-scoped throughout: every method takes a store id, so a cross-store
 * read is impossible by construction rather than by remembering a where
 * clause (CLAUDE.md section 8).
 *
 * This is deliberately separate from `ProvidesPaymentSummaries`, which Order
 * depends on for a thin read-only summary. That contract exists to be read by
 * another module; this one exists to be written by this one, and keeping them
 * apart means a change to the admin confirm flow can never ripple into what
 * Order sees.
 */
interface PaymentRepository
{
    /** Null when the payment does not exist, or belongs to another store. */
    public function findInStore(string $storeId, string $paymentId): ?Payment;

    /**
     * The same payment, read inside the caller's transaction with the row
     * locked.
     *
     * Two staff members confirming the same payment at the same moment is
     * exactly the "duplicate manual payment confirmation" scenario CLAUDE.md
     * section 12 names. Without the lock, both could read PENDING and both
     * could write PAID, and the second write would record a confirmation that
     * never should have happened.
     *
     * Must be called inside a transaction.
     */
    public function findForUpdate(string $storeId, string $paymentId): ?Payment;

    /**
     * A page of this store's payments, newest first.
     *
     * @return array{items: list<Payment>, next_cursor: string|null, has_more: bool}
     */
    public function paginate(string $storeId, PaymentCriteria $criteria): array;

    /** Whether this store already has a payment using this transfer reference. */
    public function transferReferenceExists(string $transferReference): bool;

    /**
     * Persists a payment that has already been confirmed, and records the
     * move.
     *
     * One call, so the status column and the event row can never disagree:
     * both are written in the same statement pair inside the caller's
     * transaction (CLAUDE.md section 6).
     */
    public function recordConfirmation(Payment $payment, ?string $note): Payment;

    /**
     * Records an exception without changing the payment's own status.
     *
     * @return Payment the same payment, with the new discrepancy visible in `discrepancies`
     */
    public function recordDiscrepancy(Payment $payment, PaymentDiscrepancy $discrepancy): Payment;

    /**
     * Attaches a dynamically generated VietQR to a payment - either the first
     * one, on behalf of whatever created the order (the demo seeder today,
     * checkout once it exists), or a fresh one replacing an expired or lost
     * one, on behalf of an operator (CLAUDE.md section 6's "configurable
     * expiry" for a pending transfer).
     *
     * `$expiresAt` is left untouched (null) for the first case: the window a
     * customer has to pay is decided once, by whatever created the payment
     * row, not by the act of encoding it into a QR. A regenerate call passes
     * a fresh deadline explicitly instead.
     */
    public function attachBankTransferInstructions(
        string $storeId,
        string $paymentId,
        string $bankAccountId,
        string $bankBin,
        string $bankAccountNumber,
        string $bankAccountName,
        string $transferReference,
        string $qrPayload,
        ?DateTimeImmutable $expiresAt = null,
    ): Payment;
}
