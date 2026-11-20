<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Contract;

use App\Modules\Catalog\Application\DTO\VariantSnapshot;

/**
 * Catalog's exported read model, and the only way into it from outside.
 *
 * Other modules depend on this application service instead of reading the
 * products or product_variants tables, so the catalog's schema stays private
 * to the catalog (CLAUDE.md section 3).
 */
interface ProvidesVariantSnapshots
{
    /**
     * Snapshots for the given variants of one store, keyed by variant id.
     *
     * A variant that does not exist, or belongs to another store, is simply
     * absent from the result. The caller decides what that means: for a menu
     * write it is a validation failure, for a menu read it is an item to leave
     * out.
     *
     * @param  list<string>  $variantIds
     * @return array<string, VariantSnapshot>
     */
    public function forStore(string $storeId, array $variantIds): array;
}
