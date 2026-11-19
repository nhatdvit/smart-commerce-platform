<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Contract;

use App\Modules\Catalog\Domain\Entity\Product;
use App\Modules\Catalog\Domain\Enum\CatalogStatus;

/**
 * Persistence seam for products.
 *
 * The application layer orchestrates through this interface so a use case can
 * be read, and tested, without Eloquent (CLAUDE.md sections 3 and 16). Every
 * method takes a store id: there is no way to ask this repository a question
 * that is not scoped to one store, which is how cross-store reads stay
 * impossible rather than merely unlikely.
 */
interface ProductRepository
{
    /** Null when the product does not exist, or belongs to another store. */
    public function findInStore(string $storeId, string $productId): ?Product;

    /**
     * Whether a live product in this store already carries this name.
     *
     * Case-insensitive, and ignoring archived rows: a retired product must not
     * keep its name reserved forever. $exceptProductId excludes the product
     * being edited, so saving it unchanged is not a collision with itself.
     */
    public function liveNameExists(string $storeId, string $name, ?string $exceptProductId = null): bool;

    /** The same question for the slug, which is unique per store for live rows. */
    public function liveSlugExists(string $storeId, string $slug, ?string $exceptProductId = null): bool;

    /**
     * A page of products, newest first.
     *
     * @param  list<CatalogStatus>  $statuses  empty means every status
     * @return array{items: list<Product>, next_cursor: string|null, has_more: bool}
     */
    public function paginate(
        string $storeId,
        array $statuses,
        ?string $search,
        ?string $cursor,
        int $limit,
    ): array;

    /** Returns the product as stored, with its generated ids. */
    public function create(Product $product): Product;

    /**
     * Replaces a stored product and its variant set.
     *
     * A variant that is absent from $product is archived, never deleted: it may
     * be referenced by a menu or by order history (CLAUDE.md section 6).
     */
    public function update(string $productId, Product $product): Product;
}
