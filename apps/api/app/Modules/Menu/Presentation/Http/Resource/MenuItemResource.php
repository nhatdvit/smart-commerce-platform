<?php

declare(strict_types=1);

namespace App\Modules\Menu\Presentation\Http\Resource;

use App\Modules\Menu\Application\DTO\MenuItemView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `MenuItem` schema from the designed contract.
 *
 * @property-read MenuItemView $resource
 */
final class MenuItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $item = $this->resource->item;
        $variant = $this->resource->variant;

        return [
            'id' => $item->id,
            'product' => [
                'id' => $variant->productId,
                'name' => $variant->productName,
                'slug' => $variant->productSlug,
                'description' => $variant->productDescription,
                'image_url' => $variant->productImageUrl,
                'icon' => $variant->productIcon,
                'category' => $variant->categoryId === null ? null : [
                    'id' => $variant->categoryId,
                    'name' => $variant->categoryName,
                    'slug' => $variant->categorySlug,
                ],
            ],
            'variant' => [
                'id' => $variant->variantId,
                'name' => $variant->variantName,
                'sku' => $variant->sku,
            ],
            // The store price, which overrides the variant default.
            'price' => $item->price->jsonSerialize(),
            'is_available' => $item->isAvailable,
            'available_quantity' => $item->availableQuantity,
            'daily_capacity' => $item->dailyCapacity,
            'sort_order' => $item->sortOrder,
            // Modifiers are designed but not built in this milestone.
            'modifier_groups' => [],
        ];
    }
}
