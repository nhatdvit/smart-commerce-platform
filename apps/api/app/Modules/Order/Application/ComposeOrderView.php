<?php

declare(strict_types=1);

namespace App\Modules\Order\Application;

use App\Modules\Order\Application\DTO\OrderView;
use App\Modules\Order\Domain\Entity\Order;
use App\Modules\Payment\Application\Contract\ProvidesPaymentSummaries;

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
    public function __construct(private ProvidesPaymentSummaries $payments) {}

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
}
