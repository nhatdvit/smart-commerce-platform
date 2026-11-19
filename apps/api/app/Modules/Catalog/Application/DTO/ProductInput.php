<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTO;

use App\Modules\Catalog\Domain\Enum\CatalogStatus;
use App\Modules\Catalog\Domain\ValueObject\ProductAttributes;

/**
 * A product write as the caller asked for it.
 *
 * The transport is already gone by this point: amounts are Money, statuses are
 * enums, and the category is either an id or a name to resolve. That is what
 * lets the same use case serve the HTTP endpoint today and a future AI tool or
 * import without either of them reaching into the other's concerns (CLAUDE.md
 * sections 3 and 11).
 */
final readonly class ProductInput
{
    /**
     * @param  list<VariantInput>  $variants
     */
    public function __construct(
        public string $name,
        public CatalogStatus $status,
        public array $variants,
        public ?string $slug = null,
        public ?string $categoryId = null,
        /** Resolved against this store's categories, created when it is new. */
        public ?string $categoryName = null,
        public ?string $description = null,
        public bool $inventoryTracking = true,
        public ?string $imageUrl = null,
        public ?string $icon = null,
        public ?ProductAttributes $attributes = null,
    ) {}
}
