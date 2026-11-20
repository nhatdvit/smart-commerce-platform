<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Http\Resource;

use App\Modules\Order\Domain\Entity\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `OrderItem` schema from the designed contract.
 *
 * Everything here is the snapshot stored on the line. Nothing is read from the
 * catalogue, which is the whole point: a receipt from today does not change
 * when a dish is renamed tomorrow (CLAUDE.md section 6).
 *
 * `modifiers` is an empty array because modifier groups are designed but not
 * stored yet - the menu answers `modifier_groups: []` too. An empty list is
 * the truthful answer, not a field quietly missing from a documented response.
 *
 * @property-read OrderItem $resource
 */
final class OrderItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $item = $this->resource;

        return [
            'id' => $item->id,
            'product_id' => $item->productId,
            'variant_id' => $item->variantId,
            'product_name' => $item->productName,
            'variant_name' => $item->variantName,
            'quantity' => $item->quantity,
            'note' => $item->note,
            'unit_price' => $item->unitPrice->jsonSerialize(),
            'modifiers_total' => $item->modifiersTotal->jsonSerialize(),
            'line_total' => $item->lineTotal->jsonSerialize(),
            'modifiers' => [],
        ];
    }
}
