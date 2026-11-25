<?php

declare(strict_types=1);

namespace App\Modules\Order\Application;

use App\Modules\Order\Application\DTO\OrderView;
use App\Modules\Order\Domain\Contract\OrderRepository;
use App\Modules\Order\Domain\Entity\Order;
use App\Modules\Payment\Application\Contract\ProvidesPaymentSummaries;
use App\Modules\Payment\Domain\Enum\PaymentStatus;

/**
 * Joins orders to their payments for display.
 *
 * One call to Payment's exported read model for a whole page, rather than one
 * per order, and through the contract rather than by joining the payments
 * table (CLAUDE.md section 3). The queue screen is the hottest read in the
 * application; an N+1 here would be felt on every shift.
 */
final readonly class ComposeOrderView
{
    public function __construct(
        private ProvidesPaymentSummaries $payments,
        private OrderRepository $orders,
    ) {}

    public function forOrder(string $storeId, Order $order): OrderView
    {
        $id = (string) $order->id;
        $snapshots = $this->payments->forOrders($storeId, [$id]);

        return new OrderView($order, $snapshots[$id] ?? null);
    }

    /**
     * @param  list<Order>  $orders
     * @return list<OrderView>
     */
    public function forOrders(string $storeId, array $orders): array
    {
        $snapshots = $this->payments->forOrders(
            $storeId,
            array_values(array_map(static fn (Order $order): string => (string) $order->id, $orders)),
        );

        return array_values(array_map(
            static fn (Order $order): OrderView => new OrderView($order, $snapshots[(string) $order->id] ?? null),
            $orders,
        ));
    }

    /**
     * How many `PENDING_PAYMENT` orders a customer has already reported as
     * transferred - the ones genuinely waiting on staff, as opposed to one
     * nobody has done anything about yet. The operations queue shows these
     * alongside `PLACED`; the sidebar badge counts them the same way, so the
     * two never disagree (CLAUDE.md section 6 - this is a display grouping,
     * never a status of its own).
     */
    public function countAwaitingPaymentConfirmation(string $storeId): int
    {
        $orderIds = $this->orders->pendingPaymentOrderIds($storeId);

        if ($orderIds === []) {
            return 0;
        }

        $snapshots = $this->payments->forOrders($storeId, $orderIds);

        return count(array_filter(
            $snapshots,
            static fn ($snapshot): bool => $snapshot->status === PaymentStatus::Processing,
        ));
    }
}
