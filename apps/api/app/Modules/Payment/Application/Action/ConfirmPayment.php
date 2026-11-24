<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Action;

use App\Modules\Identity\Application\StaffDirectory;
use App\Modules\Payment\Application\DTO\ConfirmPaymentInput;
use App\Modules\Payment\Domain\Contract\PaymentRepository;
use App\Modules\Payment\Domain\Entity\Payment;
use App\Modules\Payment\Domain\Enum\DiscrepancyType;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;
use App\Shared\Application\Contract\RecordsDomainEvents;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Records that a payment's money actually arrived.
 *
 * This is the only application-layer action that may move a payment to
 * `PAID`, and it is a statement of fact by a named person - never inferred
 * from a webhook, a screenshot, or a customer's own say-so (CLAUDE.md section
 * 6). Everything that has to happen together happens in one transaction: the
 * row is locked, the state machine is checked, the status and its event are
 * written, a discrepancy is recorded if one was accepted, the audit line is
 * written, and the domain event is appended to the outbox.
 *
 * Idempotency against a RETRIED request is `EnsureIdempotency` on the route
 * (CLAUDE.md requires an `Idempotency-Key`); idempotency against a SECOND,
 * independent confirmation - two staff members, two tabs - is the entity's
 * own `isSettled()` check, enforced here under a row lock so the second
 * caller sees the committed result rather than a stale read.
 */
final readonly class ConfirmPayment
{
    public function __construct(
        private PaymentRepository $payments,
        private RecordsAuditTrail $audit,
        private RecordsDomainEvents $events,
        private StaffDirectory $staff,
    ) {}

    public function handle(
        string $storeId,
        string $paymentId,
        ConfirmPaymentInput $input,
        string $actorId,
    ): Payment {
        return DB::transaction(function () use ($storeId, $paymentId, $input, $actorId): Payment {
            // Locked for the rest of the transaction: the row a second,
            // concurrent confirmation would otherwise read as still PENDING.
            $existing = $this->payments->findForUpdate($storeId, $paymentId);

            if ($existing === null) {
                // A payment of another store reads as missing: its existence
                // is not this store's information (CLAUDE.md section 8).
                throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Payment not found');
            }

            // Classified against the OWED amount before the transition, since
            // confirm() keeps that amount unchanged but this is the one place
            // that still has both numbers in hand to build the record from.
            $mismatch = $existing->classifyMismatch($input->amount);

            // CLAUDE.md section 6 (decision dated 2026-11-24): an overpayment
            // is never left as a bare number - the operator must say what
            // becomes of the excess in the same call that accepts it. Checked
            // here, before confirm() changes anything, rather than inside the
            // entity: it is a request-shape requirement ("you must tell us"),
            // not a payment-state rule the way isSettled()/amount matching are.
            if ($mismatch === DiscrepancyType::Overpaid
                && $input->acceptDiscrepancy
                && $input->overpaymentDisposition === null
            ) {
                throw new BusinessRuleViolation(
                    ErrorCode::PaymentAmountMismatch,
                    'An overpayment must say what becomes of the excess',
                    [[
                        'field' => 'overpayment_disposition',
                        'message' => 'Vui lòng chọn cách xử lý số tiền thừa trước khi xác nhận.',
                    ]],
                );
            }

            $confirmed = $existing->confirm(
                $input->amount,
                $input->bankTransactionReference,
                $input->observedAt ?? now()->toImmutable(),
                $input->acceptDiscrepancy,
                $actorId,
            );

            $saved = $this->payments->recordConfirmation($confirmed, $input->note);

            if ($mismatch !== null) {
                $saved = $this->payments->recordDiscrepancy(
                    $saved,
                    $existing->flag(
                        $mismatch,
                        $input->amount,
                        $input->note,
                        $actorId,
                        $this->staff->namesByIds([$actorId])[$actorId] ?? null,
                        $input->observedAt ?? now()->toImmutable(),
                        $mismatch === DiscrepancyType::Overpaid ? $input->overpaymentDisposition : null,
                    ),
                );
            }

            $this->audit->record(
                AuditEntry::for('payment.confirm', 'payment', $paymentId, [
                    'order_code' => $saved->orderCode,
                    'amount' => $input->amount->amount,
                    'amount_owed' => $existing->amount->amount,
                    'bank_transaction_reference' => $input->bankTransactionReference,
                    'discrepancy' => $mismatch?->value,
                    'overpayment_disposition' => $mismatch === DiscrepancyType::Overpaid
                        ? $input->overpaymentDisposition?->value
                        : null,
                ])->inStore($storeId)->by($actorId),
            );

            $this->events->record(OutboxMessage::for('payment', $paymentId, 'payment.confirmed', [
                'payment_id' => $paymentId,
                'order_id' => $saved->orderId,
                'order_code' => $saved->orderCode,
                'store_id' => $storeId,
                'amount' => $input->amount->amount,
                'confirmed_by' => $actorId,
                'discrepancy' => $mismatch?->value,
            ]));

            return $saved;
        });
    }
}
