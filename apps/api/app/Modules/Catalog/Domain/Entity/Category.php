<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Entity;

/**
 * A grouping of products within one store's catalogue.
 *
 * Thin on purpose: in this slice a category is a name a product belongs to.
 * The nesting the contract allows (`parent_id`) exists in the schema but no
 * feature drives it yet, so no behaviour is invented for it here.
 */
final readonly class Category
{
    public function __construct(
        public string $id,
        public string $storeId,
        public string $name,
        public string $slug,
        public int $sortOrder = 0,
    ) {}
}
