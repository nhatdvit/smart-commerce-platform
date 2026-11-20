<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTO;

use App\Shared\Domain\ValueObject\Money;

/**
 * What another module is allowed to know about a variant.
 *
 * The Menu module needs a product's name, category and thumbnail to render an
 * item, and needs to know whether the variant is still listable before putting
 * it on sale. It gets that from this exported read model rather than by
 * querying the catalog tables, which would couple the two modules at the
 * database and break the boundary CLAUDE.md section 3 draws.
 */
final readonly class VariantSnapshot
{
    public function __construct(
        public string $variantId,
        public string $variantName,
        public ?string $sku,
        public Money $basePrice,
        public string $productId,
        public string $productName,
        public string $productSlug,
        public ?string $productDescription,
        public ?string $productImageUrl,
        public ?string $productIcon,
        public ?string $categoryId,
        public ?string $categoryName,
        public ?string $categorySlug,
        /** Both the variant and its product are ACTIVE, so a menu may list it. */
        public bool $isListable,
    ) {}
}
