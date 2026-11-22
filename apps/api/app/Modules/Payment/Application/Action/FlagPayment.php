<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Action;

use App\Modules\Identity\Application\StaffDirectory;
use App\Modules\Payment\Application\DTO\FlagPaymentInput;
use App\Modules\Payment\Domain\Contract\PaymentRepository;
use App\Modules\Payment\Domain\Entity\Payment;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Records an exception without forcing a payment to `PAID` or `FAILED`.
 *
 * For the cases that have no correct status to force: a transfer with no
 * recognisable reference, a duplicate credit, money that landed after the
 * order was cancelled. The payment keeps its status and gains a documented
 * discrepancy, so it stays in the reconciliation queue with an explanation
 * instead of being closed to make the list shorter (CLAUDE.md section 6).
 *
 * Resolution is deliberately not here. Refund, manual match or writing it off
 * is a store policy decision section 15 leaves open, so this action only
 * records what happened, never decides what to do about it.
 */
final readonly class FlagPayment
{
    public function __construct(
        private PaymentRepository $payments,
        private RecordsAuditTrail $audit,
        private StaffDirectory $staff,
    ) {}

    public function handle(
        string $storeId,
        string $paymentId,
        FlagPaymentInput $input,
        string $actorId,
    ): Payment {
        return DB::transaction(function () use ($storeId, $paymentId, $input, $actorId): Payment {
            $payment = $this->payments->findForUpdate($storeId, $paymentId);

            if ($payment === null) {
                throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Payment not found');
            }

            $at = now()->toImmutable();
            $actorName = $this->staff->namesByIds([$actorId])[$actorId] ?? null;

            $discrepancy = $payment->flag(
                $input->type,
                $input->observedAmount,
                $input->note,
                $actorId,
                $actorName,
                $at,
            );

            $saved = $this->payments->recordDiscrepancy($payment, $discrepancy);

            $this->audit->record(
                AuditEntry::for('payment.flag', 'payment', $paymentId, [
                    'order_code' => $payment->orderCode,
                    'type' => $input->type->value,
                    'observed_amount' => $input->observedAmount?->amount,
                    'note' => $input->note,
                ])->inStore($storeId)->by($actorId),
            );

            return $saved;
        });
    }
}
