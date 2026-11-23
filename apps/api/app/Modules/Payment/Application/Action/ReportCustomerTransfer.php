<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Action;

use App\Modules\Payment\Domain\Contract\PaymentRepository;
use App\Modules\Payment\Domain\Entity\Payment;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use DateTimeImmutable;

/**
 * A customer saying "I have transferred", for their own order's payment.
 *
 * The one deliberately weak use case in this module: it changes nothing
 * about settlement, because nothing a customer submits here is proof
 * (CLAUDE.md section 6, decision dated 2026-11-24). It exists only so the
 * admin confirm screen has something real to show staff, next to the
 * `Số tiền thực nhận` field they still have to fill in themselves.
 *
 * No row lock: unlike `ConfirmPayment`, this can never move `status`, so two
 * reports racing each other have nothing to corrupt - last write wins, same
 * as `GenerateBankTransferInstructions` regenerating a QR.
 */
final readonly class ReportCustomerTransfer
{
    public function __construct(
        private PaymentRepository $payments,
        private RecordsAuditTrail $audit,
    ) {}

    public function handle(string $storeId, string $paymentId, ?string $receiptPath): Payment
    {
        $existing = $this->payments->findInStore($storeId, $paymentId);

        if ($existing === null) {
            throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Payment not found');
        }

        $reported = $existing->reportCustomerTransfer(new DateTimeImmutable, $receiptPath);
        $saved = $this->payments->recordCustomerTransferReport($reported);

        $this->audit->record(
            AuditEntry::for('payment.customer-transfer-report', 'payment', $paymentId, [
                'order_code' => $saved->orderCode,
                'has_receipt' => $saved->customerReceiptPath !== null,
            ])->inStore($storeId),
        );

        return $saved;
    }
}
