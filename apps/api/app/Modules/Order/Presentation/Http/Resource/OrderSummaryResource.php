<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Http\Resource;

use App\Modules\Order\Application\DTO\OrderView;
use App\Modules\Order\Domain\Entity\OrderItem;
use App\Shared\Presentation\Http\IsoTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `OrderSummary` schema: one row of the operations queue.
 *
 * It carries a short preview of the lines, because the row a staff member
 * reads says "2 bánh mì pate trứng, 1 cà phê sữa" - that is how they recognise
 * an order at a glance. The alternative, a detail request per row, would be an
 * N+1 built into the screen.
 *
 * The preview is names and quantities only. Per-line money belongs to the
 * detail response, so a queue read cannot be used to scrape a price list.
 *
 * @property-read OrderView $resource
 */
final class OrderSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->resource->order;
        $payment = $this->resource->payment;

        return [
            'id' => $order->id,
            'order_code' => $order->code,
            'status' => $order->status->value,
            // Null when the order has no payment row - a real anomaly, shown
            // as one rather than guessed at (see OrderResource).
            'payment_status' => $payment?->status->value,
            'payment_method' => $payment?->method->value,
            'fulfillment_type' => $order->fulfillmentType->value,
            'total' => $order->totals->total->jsonSerialize(),
            'item_count' => $order->lineCount(),
            'unit_count' => $order->unitCount(),
            'contact_name' => $order->contact->name,
            'items' => array_values(array_map(
                static fn (OrderItem $item): array => [
                    'product_name' => $item->productName,
                    'variant_name' => $item->variantName,
                    'quantity' => $item->quantity,
                ],
                $order->items,
            )),
            'scheduled_at' => IsoTimestamp::of($order->scheduledAt),
            'placed_at' => IsoTimestamp::of($order->placedAt),
            'created_at' => IsoTimestamp::of($order->createdAt),
        ];
    }
}
