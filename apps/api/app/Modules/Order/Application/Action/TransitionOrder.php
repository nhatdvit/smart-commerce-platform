<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Action;

use App\Modules\Order\Domain\Contract\OrderRepository;
use App\Modules\Order\Domain\Entity\Order;
use App\Modules\Order\Domain\Enum\OrderStatus;
use App\Modules\Payment\Application\Contract\ProvidesPaymentSummaries;
use App\Modules\Payment\Domain\Enum\PaymentStatus;
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
        private ProvidesPaymentSummaries $payments,
    ) {}

    /**
     * @param  bool  $paymentConfirmed  True only from
     *                                  {@see \App\Modules\Order\Application\Listener\AdvanceOrderOnPaymentConfirmed},
     *                                  reacting to `payment.confirmed` - it is the one caller allowed to place
     *                                  an order still awaiting a transfer. Never set this from an HTTP
     *                                  controller; that by-hand path is exactly what
     *                                  {@see assertSettlementIsNotDoneByHand()} exists to refuse.
     */
    public function handle(
        string $storeId,
        string $orderId,
        OrderStatus $to,
        ?string $reason = null,
        ?string $actorId = null,
        bool $paymentConfirmed = false,
    ): Order {
        return DB::transaction(function () use ($storeId, $orderId, $to, $reason, $actorId, $paymentConfirmed): Order {
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

            if (! $paymentConfirmed) {
                $this->assertSettlementIsNotDoneByHand($order, $to);
            }

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
     * by hand - but once a customer has reported the transfer, confirming the
     * order itself is a separate decision staff may make right away (CLAUDE.md
     * section 6, "Order confirmation independent of payment confirmation",
     * approved 2026-11-25): the two confirmations may happen in either order.
     *
     * `PENDING_PAYMENT -> PLACED` stays exactly as before: a legal edge of the
     * state machine, but reachable only by {@see
     * \App\Modules\Order\Application\Listener\AdvanceOrderOnPaymentConfirmed}
     * reacting to the real bank credit, never from this endpoint - that would
     * be a one-click way to queue an order nobody paid for.
     *
     * `PENDING_PAYMENT -> CONFIRMED` is reachable from here, but only once
     * {@see customerHasReportedTransfer()} says so; an order nobody has acted
     * on at all stays unconfirmable by hand, same reasoning.
     */
    private function assertSettlementIsNotDoneByHand(Order $order, OrderStatus $to): void
    {
        if ($order->status !== OrderStatus::PendingPayment) {
            return;
        }

        if ($to === OrderStatus::Placed) {
            throw new BusinessRuleViolation(
                ErrorCode::InvalidStateTransition,
                'An order awaiting a bank transfer is placed by confirming its payment, not by a status change',
                [[
                    'field' => 'to_status',
                    'message' => 'Đơn chờ chuyển khoản chỉ vào hàng đợi khi thanh toán được xác nhận ở màn hình Thanh toán.',
                ]],
            );
        }

        if ($to === OrderStatus::Confirmed && ! $this->customerHasReportedTransfer($order)) {
            throw new BusinessRuleViolation(
                ErrorCode::InvalidStateTransition,
                'An order awaiting a bank transfer can only be confirmed by hand once the customer has reported the transfer',
                [[
                    'field' => 'to_status',
                    'message' => 'Chỉ có thể xác nhận đơn này sau khi khách đã báo chuyển khoản.',
                ]],
            );
        }
    }

    /** Re-checks the real payment status itself rather than trusting the request (CLAUDE.md section 6). */
    private function customerHasReportedTransfer(Order $order): bool
    {
        $orderId = (string) $order->id;
        $snapshot = $this->payments->forOrders($order->storeId, [$orderId])[$orderId] ?? null;

        return $snapshot !== null && $snapshot->status === PaymentStatus::Processing;
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
