<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Resource;

use App\Modules\Catalog\Domain\Entity\Product;
use App\Modules\Catalog\Domain\Entity\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `Product` schema from the designed contract.
 *
 * Wraps the DOMAIN product, not an Eloquent row, so the response shape is
 * decided here and cannot drift with a column rename (CLAUDE.md section 16).
 *
 * @property-read Product $resource
 */
final class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;

        return [
            'id' => $product->id,
            'category_id' => $product->categoryId,
            'category' => $product->category === null ? null : [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ],
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'status' => $product->status->value,
            'inventory_tracking' => $product->inventoryTracking,
            'image_url' => $product->imageUrl,
            'icon' => $product->icon,
            'attributes' => $product->attributes->toArray(),
            'variants' => array_map(
                static fn (ProductVariant $variant): array => [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'name' => $variant->name,
                    // Money is always an object with its currency, and the
                    // amount is a string: a JSON number cannot hold 64-bit dong
                    // safely in every client (CLAUDE.md section 6).
                    'base_price' => $variant->basePrice->jsonSerialize(),
                    'status' => $variant->status->value,
                ],
                $product->variants,
            ),
            // Designed, not built in this milestone. An empty array is the
            // truthful answer: this product carries no modifier groups.
            'modifier_groups' => [],
        ];
    }
}
