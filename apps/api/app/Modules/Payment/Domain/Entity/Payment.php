<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Entity;

use App\Modules\Payment\Domain\Enum\DiscrepancyType;
use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Modules\Payment\Domain\Enum\PaymentStatus;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Domain\ValueObject\Money;
use App\Shared\Presentation\Http\ErrorCode;
use DateTimeImmutable;

/**
 * A payment, and the one place "the money arrived" is allowed to become true.
 *
 * `confirm()` is the only way a payment becomes `PAID`, and it enforces the
 * MVP settlement decision in CLAUDE.md section 6 at the one point that
 * matters: a payment already settled cannot be confirmed again (duplicate
 * manual confirmation is one of the critical test scenarios section 12
 * names), and an amount that does not match what is owed is refused unless
 * the caller explicitly accepts the discrepancy - under- and overpayment are
 * business decisions, never a rounding this class performs quietly.
 *
 * Immutable, like `Order`: a transition returns a new instance, so an action
 * can compare "before" and "after" when it writes the audit trail and the
 * event history, and no caller can mutate the status of a payment another
 * part of the request is still reading.
 */
final readonly class Payment
{
    /**
     * @param  list<PaymentEvent>  $events  Empty on the list projection, which does not read it.
     * @param  list<PaymentDiscrepancy>  $discrepancies  Empty on the list projection.
     */
    private function __construct(
        public ?string $id,
        public string $storeId,
        public string $orderId,
        public string $orderCode,
        public PaymentMethod $method,
        public PaymentStatus $status,
        public Money $amount,
        public ?Money $confirmedAmount,
        public ?string $transferReference,
        public ?string $qrPayload,
        public ?string $bankAccountId,
        public ?string $bankBin,
        public ?string $bankAccountNumber,
        public ?string $bankAccountName,
        public ?string $bankTransactionReference,
        public ?string $confirmedBy,
        public ?string $confirmedByName,
        public ?DateTimeImmutable $confirmedAt,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $createdAt,
        /** Set by the repository without loading the full list; see needsAttention(). */
        public bool $hasDiscrepancy,
        public array $events,
        public array $discrepancies,
    ) {}

    /**
     * @param  list<PaymentEvent>  $events
     * @param  list<PaymentDiscrepancy>  $discrepancies
     */
    public static function create(
        ?string $id,
        string $storeId,
        string $orderId,
        string $orderCode,
        PaymentMethod $method,
        PaymentStatus $status,
        Money $amount,
        ?Money $confirmedAmount = null,
        ?string $transferReference = null,
        ?string $qrPayload = null,
        ?string $bankAccountId = null,
        ?string $bankBin = null,
        ?string $bankAccountNumber = null,
        ?string $bankAccountName = null,
        ?string $bankTransactionReference = null,
        ?string $confirmedBy = null,
        ?string $confirmedByName = null,
        ?DateTimeImmutable $confirmedAt = null,
        ?DateTimeImmutable $expiresAt = null,
        ?DateTimeImmutable $createdAt = null,
        bool $hasDiscrepancy = false,
        array $events = [],
        array $discrepancies = [],
    ): self {
        if ($amount->isNegative()) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A payment cannot be for a negative amount',
                [['field' => 'amount', 'message' => 'Số tiền phải là số nguyên đồng, không âm.']],
            );
        }

        return new self(
            $id,
            $storeId,
            $orderId,
            $orderCode,
            $method,
            $status,
            $amount,
            $confirmedAmount,
            $transferReference,
            $qrPayload,
            $bankAccountId,
            $bankBin,
            $bankAccountNumber,
            $bankAccountName,
            $bankTransactionReference,
            $confirmedBy,
            $confirmedByName,
            $confirmedAt,
            $expiresAt,
            $createdAt,
            $hasDiscrepancy,
            array_values($events),
            array_values($discrepancies),
        );
    }

    /**
     * Records that the money actually arrived.
     *
     * A statement of fact by a named person, which is why `$actorId` is
     * required rather than optional: there is deliberately no way to reach
     * `PAID` without naming who saw the cash or the credit.
     *
     * Amount mismatch is not auto-resolved. The caller decides what to do
     * with the comparison via {@see classifyMismatch()} before calling this -
     * confirm() only enforces that a mismatch was explicitly accepted, it does
     * not build the discrepancy record itself, because the application layer
     * is where that record is persisted alongside this transition in one
     * transaction.
     */
    public function confirm(
        Money $confirmedAmount,
        ?string $bankTransactionReference,
        DateTimeImmutable $confirmedAt,
        bool $acceptDiscrepancy,
        string $actorId,
    ): self {
        if ($this->status->isSettled()) {
            throw new BusinessRuleViolation(
                ErrorCode::PaymentAlreadyConfirmed,
                sprintf('Payment is already %s', $this->status->value),
                [[
                    'field' => 'status',
                    'message' => sprintf('Thanh toán này đã ở trạng thái "%s".', $this->status->label()),
                ]],
            );
        }

        if ($confirmedAmount->isNegative()) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A confirmed amount cannot be negative',
                [['field' => 'amount', 'message' => 'Số tiền phải là số nguyên đồng, không âm.']],
            );
        }

        if ($this->classifyMismatch($confirmedAmount) !== null && ! $acceptDiscrepancy) {
            throw new BusinessRuleViolation(
                ErrorCode::PaymentAmountMismatch,
                sprintf(
                    'Confirmed amount %s does not match the amount owed %s',
                    $confirmedAmount->amount,
                    $this->amount->amount,
                ),
                [[
                    'field' => 'amount',
                    'message' => sprintf(
                        'Số tiền xác nhận (%s) khác số tiền đơn hàng cần thu (%s). Xác nhận lệch số tiền nếu đây là cố ý.',
                        $confirmedAmount->amount,
                        $this->amount->amount,
                    ),
                ]],
            );
        }

        return new self(
            $this->id,
            $this->storeId,
            $this->orderId,
            $this->orderCode,
            $this->method,
            PaymentStatus::Paid,
            $this->amount,
            $confirmedAmount,
            $this->transferReference,
            $this->qrPayload,
            $this->bankAccountId,
            $this->bankBin,
            $this->bankAccountNumber,
            $this->bankAccountName,
            $bankTransactionReference ?? $this->bankTransactionReference,
            $actorId,
            $this->confirmedByName,
            $confirmedAt,
            $this->expiresAt,
            $this->createdAt,
            $this->hasDiscrepancy,
            $this->events,
            $this->discrepancies,
        );
    }

    /**
     * Whether a confirmed amount would be a mismatch, and which kind.
     *
     * Pure comparison against what is owed - called before confirm() to
     * decide whether a discrepancy must be accepted, and again afterwards (on
     * the pre-confirmation instance) to build the record of it when one was.
     */
    public function classifyMismatch(Money $observedAmount): ?DiscrepancyType
    {
        if ($observedAmount->amount === $this->amount->amount) {
            return null;
        }

        return $observedAmount->amount < $this->amount->amount
            ? DiscrepancyType::Underpaid
            : DiscrepancyType::Overpaid;
    }

    /**
     * Records an exception without confirming payment.
     *
     * For the cases that must not be forced into `PAID` or `FAILED`: a
     * transfer with no recognisable reference, a duplicate credit, money that
     * landed after the order was cancelled. The payment's own status is
     * untouched - it stays in the queue with an explanation rather than being
     * closed to make the list shorter.
     */
    public function flag(
        DiscrepancyType $type,
        ?Money $observedAmount,
        ?string $note,
        string $actorId,
        ?string $actorName,
        DateTimeImmutable $at,
    ): PaymentDiscrepancy {
        return new PaymentDiscrepancy(
            $type,
            $this->amount,
            $observedAmount,
            $note,
            $actorId,
            $actorName,
            $at,
        );
    }

    /**
     * Whether a human has something to do about this payment.
     *
     * Outstanding (pending or processing) covers both an uncollected cash
     * order and a transfer nobody has verified yet, whatever its transfer
     * window says - a transfer sitting PENDING past its `expires_at` is not
     * less urgent than one still inside it, since nothing in this milestone
     * moves it on automatically (CLAUDE.md section 15 leaves the expiry job
     * open). A recorded discrepancy keeps a row flagged even once it is no
     * longer outstanding, because resolving one is explicitly not automated
     * either (section 6) - there is no "resolved" to fall back to.
     */
    public function needsAttention(): bool
    {
        return $this->status->isOutstanding() || $this->hasDiscrepancy;
    }
}
