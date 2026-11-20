<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Contract;

use App\Modules\Catalog\Domain\Entity\Category;

/** Persistence seam for categories. Store-scoped, like every catalog query. */
interface CategoryRepository
{
    /** Null when the category does not exist, or belongs to another store. */
    public function findInStore(string $storeId, string $categoryId): ?Category;

    /**
     * The category of this name in this store, creating it when it is new.
     *
     * Matched case-insensitively, so "Bánh mì" and "bánh mì" are one category.
     * This is what lets the menu form accept a typed category name instead of
     * making staff create one through a separate screen first.
     */
    public function findOrCreateByName(string $storeId, string $name): Category;
}
