<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Persistence;

use App\Modules\Catalog\Application\Contract\ProvidesVariantSnapshots;
use App\Modules\Catalog\Application\DTO\VariantSnapshot;
use App\Modules\Catalog\Infrastructure\Persistence\Model\ProductVariant as VariantModel;
use App\Shared\Domain\ValueObject\Money;

/**
 * Serves Catalog's exported read model to other modules.
 *
 * One query for a whole page of menu items rather than one per item: the Menu
 * module asks for every variant it is about to render in a single call.
 */
final class EloquentVariantSnapshotProvider implements ProvidesVariantSnapshots
{
    public function forStore(string $storeId, array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $rows = VariantModel::query()
            ->where('store_id', $storeId)
            ->whereIn('id', array_values(array_unique($variantIds)))
            ->with('product.category')
            ->get();

        $snapshots = [];

        foreach ($rows as $row) {
            $product = $row->product;

            if ($product === null) {
                // Unreachable through the schema - product_id is NOT NULL with
                // a foreign key - but a snapshot without its product would be
                // a lie, so it is left out rather than half-filled.
                continue;
            }

            $category = $product->category;

            $snapshots[$row->id] = new VariantSnapshot(
                variantId: $row->id,
                variantName: $row->name,
                sku: $row->sku,
                basePrice: Money::vnd($row->base_price_amount),
                productId: $product->id,
                productName: $product->name,
                productSlug: $product->slug,
                productDescription: $product->description,
                productImageUrl: $product->image_url,
                productIcon: $product->icon,
                categoryId: $category?->id,
                categoryName: $category?->name,
                categorySlug: $category?->slug,
                // Both have to be listable. An archived product whose variant
                // was never touched must not stay sellable through a menu.
                isListable: $row->status->isListable() && $product->status->isListable(),
            );
        }

        return $snapshots;
    }
}
