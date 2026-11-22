<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\Listener;

use App\Modules\Order\Application\Action\TransitionOrder;
use App\Modules\Order\Domain\Contract\OrderRepository;
use App\Modules\Order\Domain\Enum\OrderStatus;
use App\Shared\Infrastructure\Outbox\OutboxEventPublished;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Places an order once its bank transfer is confirmed.
 *
 * `PENDING_PAYMENT -> PLACED` is a legal edge of the order state machine, but
 * {@see TransitionOrder::assertSettlementIsNotDoneByHand()} refuses to reach
 * it from the ordinary "move this order" endpoint - that move belongs to
 * whoever checked the real bank credit, which is exactly this listener,
 * reacting to the `payment.confirmed` event `ConfirmPayment` records. This is
 * the one caller that passes `paymentConfirmed: true`.
 *
 * A cash confirmation fires the same event - cash and bank transfer share one
 * `payment.confirmed` event type - but a cash order starts at `PLACED`
 * already (CLAUDE.md section 6: `PENDING_PAYMENT` is not the only way in), so
 * the pre-check below makes that the ordinary case, not a special one.
 *
 * **Idempotent by construction.** `OutboxEventPublished`'s delivery is
 * at-least-once; a second delivery finds the order no longer
 * `PENDING_PAYMENT` and does nothing.
 */
final readonly class AdvanceOrderOnPaymentConfirmed
{
    public function __construct(
        private OrderRepository $orders,
        private TransitionOrder $transitionOrder,
    ) {}

    public function handle(OutboxEventPublished $event): void
    {
        if ($event->message->eventType !== 'payment.confirmed') {
            return;
        }

        $payload = $event->message->payload;
        $storeId = $payload['store_id'] ?? null;
        $orderId = $payload['order_id'] ?? null;

        if (! is_string($storeId) || ! is_string($orderId)) {
            return;
        }

        $order = $this->orders->findInStore($storeId, $orderId);

        if ($order === null || $order->status !== OrderStatus::PendingPayment) {
            return;
        }

        try {
            $this->transitionOrder->handle(
                $storeId,
                $orderId,
                OrderStatus::Placed,
                paymentConfirmed: true,
            );
        } catch (Throwable $e) {
            // One order failing to advance is not worth losing every other
            // outbox listener's work over in the same publish job - the
            // payment itself is already safely recorded by ConfirmPayment
            // regardless, so nothing about settlement is lost here. Logged so
            // a stuck PENDING_PAYMENT order is diagnosable rather than silent.
            Log::error('Failed to place order after payment confirmation', [
                'order_id' => $orderId,
                'store_id' => $storeId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
