<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Http\Resource;

use App\Modules\Order\Application\DTO\OrderView;
use App\Modules\Order\Domain\Entity\OrderStatusChange;
use App\Shared\Presentation\Http\IsoTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `Order` schema from the designed contract.
 *
 * Two fields answer honestly rather than plausibly:
 *
 *   - `payment` is null when the order has no payment row. That is a real
 *     anomaly from a half-finished checkout, and a screen should be able to
 *     show it as such instead of being told the order is unpaid cash.
 *   - `fulfillment_status` is null because the Fulfillment module is designed
 *     and not built. Reporting the order's own status here instead would
 *     invent a pickup/delivery state nothing maintains.
 *
 * @property-read OrderView $resource
 */
final class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->resource->order;
        $payment = $this->resource->payment;
        $totals = $order->totals;

        return [
            'id' => $order->id,
            'order_code' => $order->code,
            'store_id' => $order->storeId,
            'status' => $order->status->value,
            'fulfillment_type' => $order->fulfillmentType->value,
            'fulfillment_status' => null,
            'scheduled_at' => IsoTimestamp::of($order->scheduledAt),
            'contact' => [
                'name' => $order->contact->name,
                'phone' => $order->contact->phone,
                'email' => $order->contact->email,
            ],
            'delivery_address_line' => $order->deliveryAddressLine,
            'note' => $order->note,
            'items' => OrderItemResource::collection($order->items)->resolve(),
            'totals' => [
                'subtotal' => $totals->subtotal->jsonSerialize(),
                'discount' => $totals->discount->jsonSerialize(),
                'delivery_fee' => $totals->deliveryFee->jsonSerialize(),
                'tax' => $totals->tax->jsonSerialize(),
                'total' => $totals->total->jsonSerialize(),
            ],
            'payment' => $payment === null ? null : (new PaymentSummaryResource($payment))->resolve(),
            'status_history' => array_values(array_map(
                static fn (OrderStatusChange $change): array => [
                    'from_status' => $change->from?->value,
                    'to_status' => $change->to->value,
                    // Null when the system made the move - a scheduled job
                    // expiring an unpaid transfer - or when the account is gone.
                    'actor_name' => $change->actorName,
                    'reason' => $change->reason,
                    'created_at' => IsoTimestamp::of($change->at),
                ],
                $order->statusHistory,
            )),
            'placed_at' => IsoTimestamp::of($order->placedAt),
            'created_at' => IsoTimestamp::of($order->createdAt),
        ];
    }
}
