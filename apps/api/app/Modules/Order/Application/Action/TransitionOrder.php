<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Action;

use App\Modules\Order\Domain\Contract\OrderRepository;
use App\Modules\Order\Domain\Entity\Order;
use App\Modules\Order\Domain\Enum\OrderStatus;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;
use App\Shared\Application\Contract\RecordsDomainEvents;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Moves one order to its next status.
 *
 * Everything that has to happen together happens in one transaction: the row
 * is locked, the state machine is checked, the status and its history row are
 * written, the audit line is recorded and the domain event is appended to the
 * outbox (CLAUDE.md sections 6 and 16). Either the order moved and the world
 * knows, or nothing happened at all.
 *
 * **Moving an order does not collect money.** Nothing in here touches the
 * payment: an order reaching COMPLETED with its payment still PENDING is an
 * uncollected cash order, which is a real state a shop needs to see rather
 * than a bug to paper over.
 *
 * Exposed as a use case independent of HTTP, so the kitchen display and the
 * expiry job can call the same validated operation later (section 3).
 */
final readonly class TransitionOrder
{
    public function __construct(
        private OrderRepository $orders,
        private RecordsAuditTrail $audit,
        private RecordsDomainEvents $events,
    ) {}

    public function handle(
        string $storeId,
        string $orderId,
        OrderStatus $to,
        ?string $reason = null,
        ?string $actorId = null,
    ): Order {
        return DB::transaction(function () use ($storeId, $orderId, $to, $reason, $actorId): Order {
            // Locked for the rest of the transaction: two staff members
            // advancing the same order at the same moment is ordinary in a
            // busy shop, and without this both would read PREPARING and both
            // would write READY.
            $order = $this->orders->findForUpdate($storeId, $orderId);

            if ($order === null) {
                // An order of another store reads as missing: its existence is
                // not this store's information (CLAUDE.md section 8).
                throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Order not found');
            }

            // The entity owns the state machine, so this is where an illegal
            // move is refused - with a 409, never a silent no-op. It runs
            // first on purpose: "a finished order cannot be cancelled" is the
            // more useful answer than "say why you are cancelling it".
            $moved = $order->transitionTo($to);

            $this->assertSettlementIsNotDoneByHand($order, $to);
            $reason = $this->requireReasonWhereItMatters($to, $reason);

            $saved = $this->orders->recordTransition($moved, $order->status, $actorId, $reason);

            $this->audit->record(
                AuditEntry::for('order.order.transition', 'order', $orderId, [
                    'order_code' => $saved->code,
                    'status' => ['from' => $order->status->value, 'to' => $saved->status->value],
                    'reason' => $reason,
                ])->inStore($storeId)->by($actorId),
            );

            // One event type with the move in the payload, rather than eight
            // event types a listener would have to enumerate. Consumers match
            // on `order.status-changed` and read `to`.
            $this->events->record(OutboxMessage::for('order', $orderId, 'order.status-changed', [
                'order_id' => $orderId,
                'order_code' => $saved->code,
                'store_id' => $storeId,
                'from' => $order->status->value,
                'to' => $saved->status->value,
                'actor_id' => $actorId,
                'reason' => $reason,
            ]));

            return $saved;
        });
    }

    /**
     * An order waiting for a transfer is placed by confirming the money, not
     * by hand.
     *
     * `PENDING_PAYMENT -> PLACED` is a legal edge of the state machine - it is
     * how a verified transfer puts an order into service - but it belongs to
     * the payment confirmation flow, which checks the real bank credit and
     * records who confirmed it. Reachable from this endpoint, it would be a
     * one-click way to serve an order nobody paid for, which is exactly what
     * CLAUDE.md section 6 forbids.
     */
    private function assertSettlementIsNotDoneByHand(Order $order, OrderStatus $to): void
    {
        if ($order->status !== OrderStatus::PendingPayment || $to !== OrderStatus::Placed) {
            return;
        }

        throw new BusinessRuleViolation(
            ErrorCode::InvalidStateTransition,
            'An order awaiting a bank transfer is placed by confirming its payment, not by a status change',
            [[
                'field' => 'to_status',
                'message' => 'Đơn chờ chuyển khoản chỉ vào hàng đợi khi thanh toán được xác nhận ở màn hình Thanh toán.',
            ]],
        );
    }

    /** Cancelling is the move a customer will ask about, so it has to say why. */
    private function requireReasonWhereItMatters(OrderStatus $to, ?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);
        $reason = $reason === '' ? null : $reason;

        if ($to->requiresReason() && $reason === null) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'Cancelling an order requires a reason',
                [['field' => 'reason', 'message' => 'Vui lòng nhập lý do hủy đơn.']],
            );
        }

        return $reason;
    }
}
