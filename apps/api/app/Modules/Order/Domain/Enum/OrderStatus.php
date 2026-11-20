<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Enum;

/**
 * The order lifecycle, and the only definition of which moves are legal.
 *
 * A SEPARATE state machine from payment status (CLAUDE.md section 6).
 * `Completed` never implies paid: handing a customer their bánh mì and
 * collecting their money are two different events, and conflating them is how
 * a shop loses cash.
 *
 * Default prepaid path:
 * `PENDING_PAYMENT -> PLACED -> CONFIRMED -> PREPARING -> READY -> COMPLETED`.
 * A cash order starts at `PLACED` and may progress before payment, which is
 * why `PENDING_PAYMENT` is not on the only way in.
 */
enum OrderStatus: string
{
    /** A bank transfer was chosen and the money has not been verified yet. */
    case PendingPayment = 'PENDING_PAYMENT';

    /** The customer is committed; the store has not acknowledged it yet. */
    case Placed = 'PLACED';

    case Confirmed = 'CONFIRMED';
    case Preparing = 'PREPARING';
    case Ready = 'READY';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    /** The transfer window closed without the money arriving. */
    case Expired = 'EXPIRED';

    /**
     * The statuses this one may move to, and nothing else.
     *
     * Forward one step, or out to a terminal state. There is no step back:
     * an order that has been prepared cannot un-prepare, and correcting a
     * mistaken move is a new, recorded decision rather than an undo
     * (CLAUDE.md section 6 requires every transition to be validated and
     * recorded).
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            // Placed is reached by confirming the payment, not by hand; see
            // Application\Action\TransitionOrder.
            self::PendingPayment => [self::Placed, self::Cancelled, self::Expired],
            self::Placed => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Preparing, self::Cancelled],
            self::Preparing => [self::Ready, self::Cancelled],
            self::Ready => [self::Completed, self::Cancelled],
            // Terminal. A late transfer is handled by reconciliation and a
            // refund decision, never by reopening the order (section 6).
            self::Completed, self::Cancelled, self::Expired => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /** Still in the queue: staff have something left to do with it. */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /** Has entered the service flow, so it carries a `placed_at`. */
    public function isInService(): bool
    {
        return match ($this) {
            self::Placed, self::Confirmed, self::Preparing, self::Ready, self::Completed => true,
            self::PendingPayment, self::Cancelled, self::Expired => false,
        };
    }

    /**
     * A step the kitchen owns.
     *
     * `kitchen.operate` alone is enough to reach these two, because they are
     * what a cook does all morning and waiting for a manager would stop
     * service. Completing an order and cancelling one are not here: those
     * touch money and the customer (CLAUDE.md section 8).
     */
    public function isKitchenStep(): bool
    {
        return $this === self::Preparing || $this === self::Ready;
    }

    /** Cancelling has to say why; it is the move a customer will ask about. */
    public function requiresReason(): bool
    {
        return $this === self::Cancelled;
    }

    /** Vietnamese label, for messages the operator reads. */
    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Chờ chuyển khoản',
            self::Placed => 'Chờ xác nhận',
            self::Confirmed => 'Đã xác nhận',
            self::Preparing => 'Đang chuẩn bị',
            self::Ready => 'Sẵn sàng',
            self::Completed => 'Hoàn thành',
            self::Cancelled => 'Đã hủy',
            self::Expired => 'Hết hạn',
        };
    }
}
