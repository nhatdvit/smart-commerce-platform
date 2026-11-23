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
 * A customer cancelling their own order.
 *
 * Deliberately NOT a call to {@see TransitionOrder}: that action is gated by
 * staff RBAC and allows CANCELLED from any open status (PendingPayment
 * through Ready), because a shop may cancel a late order for its own
 * reasons. A customer gets a narrower rule - only before the kitchen has
 * actually started (CLAUDE.md section 6, "Reject invalid transitions") -
 * enforced here rather than in {@see Order::transitionTo} or
 * {@see OrderStatus}, both of which are
 * shared with the admin/kitchen/expiry flows and must not carry a
 * customer-only policy.
 *
 * No reason is required, unlike a staff cancellation: a customer does not
 * owe themselves an explanation. `TransitionOrder::requireReasonWhereItMatters()`
 * is staff-only for the same reason this is its own action rather than a
 * thin wrapper around it.
 *
 * Stock reservation release is not called here: `stock_reservations` is
 * Inventory module work that does not exist yet (CLAUDE.md section 15;
 * see CreateOrder's own doc comment for the same gap at creation time).
 */
final readonly class CancelOrder
{
    /** Anything from CONFIRMED onward is "the kitchen may already be on it" - a conversation with the store, not an API call. */
    private const array CUSTOMER_CANCELLABLE = [
        OrderStatus::PendingPayment,
        OrderStatus::Placed,
        OrderStatus::Confirmed,
    ];

    public function __construct(
        private OrderRepository $orders,
        private RecordsAuditTrail $audit,
        private RecordsDomainEvents $events,
    ) {}

    public function handle(string $storeId, string $orderId, ?string $actorId, ?string $reason): Order
    {
        return DB::transaction(function () use ($storeId, $orderId, $actorId, $reason): Order {
            // Locked for the rest of the transaction, the same reason every
            // other status move is: staff confirming the payment and the
            // customer cancelling at the same moment must not race.
            $order = $this->orders->findForUpdate($storeId, $orderId);

            if ($order === null) {
                throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Order not found');
            }

            if (! in_array($order->status, self::CUSTOMER_CANCELLABLE, true)) {
                throw new BusinessRuleViolation(
                    ErrorCode::InvalidStateTransition,
                    sprintf('Order cannot be self-cancelled from %s', $order->status->value),
                    [[
                        'field' => 'status',
                        'message' => sprintf(
                            'Đơn đang ở trạng thái "%s" nên không thể tự hủy. Vui lòng liên hệ cửa hàng.',
                            $order->status->label(),
                        ),
                    ]],
                );
            }

            $moved = $order->transitionTo(OrderStatus::Cancelled);
            $reason = $this->normalizeReason($reason);

            $saved = $this->orders->recordTransition($moved, $order->status, $actorId, $reason);

            $this->audit->record(
                AuditEntry::for('order.order.cancel', 'order', $orderId, [
                    'order_code' => $saved->code,
                    'status' => ['from' => $order->status->value, 'to' => $saved->status->value],
                    'reason' => $reason,
                ])->inStore($storeId)->by($actorId),
            );

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

    private function normalizeReason(?string $reason): ?string
    {
        $trimmed = $reason === null ? null : trim($reason);

        return $trimmed === '' ? null : $trimmed;
    }
}
