<?php

declare(strict_types=1);

namespace App\Modules\Menu\Domain\Contract;

use App\Modules\Menu\Domain\Entity\Menu;
use App\Modules\Menu\Domain\Entity\MenuItem;

/**
 * Persistence seam for menus.
 *
 * Store-scoped throughout, like the catalog's: a menu id alone is never enough
 * to read or write anything (CLAUDE.md section 8).
 */
interface MenuRepository
{
    /** Null when the menu does not exist, or belongs to another store. */
    public function findInStore(string $storeId, string $menuId): ?Menu;

    /**
     * Every menu of this store, oldest first.
     *
     * A store has a handful of menus, not a page of them, so this is not
     * paginated; the endpoint still answers with the designed page envelope.
     *
     * @return list<Menu>
     */
    public function allInStore(string $storeId): array;

    /**
     * Replaces the menu's name, status and item set.
     *
     * Items absent from $menu are removed from the menu by being marked
     * deleted, never erased: an order line will reference one, and "no longer
     * on the menu" must not read as "never sold" (CLAUDE.md section 7).
     */
    public function replace(Menu $menu): Menu;

    /** Null when the item does not exist, or belongs to another store. */
    public function findItemInStore(string $storeId, string $menuItemId): ?MenuItem;

    /** Persists one item's availability and portion counts. */
    public function saveItemAvailability(string $storeId, MenuItem $item): MenuItem;

    /** The menu an item belongs to, for the response and for authorization. */
    public function menuIdForItem(string $storeId, string $menuItemId): ?string;
}
