<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application;

use App\Modules\Catalog\Application\Contract\ProvidesVariantSnapshots;
use App\Modules\Menu\Application\DTO\MenuItemView;
use App\Modules\Menu\Application\DTO\MenuView;
use App\Modules\Menu\Domain\Entity\Menu;
use App\Modules\Menu\Domain\Entity\MenuItem;

/**
 * Joins a menu to the catalog facts needed to show it.
 *
 * One call to Catalog's exported read model for the whole menu, rather than one
 * per item, and through the contract rather than by joining the catalog's
 * tables (CLAUDE.md section 3).
 *
 * It also applies the rule that makes archiving a product a complete removal:
 * an item whose variant or product is no longer listable is dropped from the
 * view. Without it, "delete this dish" would leave the dish on the menu,
 * priced and apparently for sale.
 */
final readonly class ComposeMenuView
{
    public function __construct(private ProvidesVariantSnapshots $catalog) {}

    public function forMenu(string $storeId, Menu $menu): MenuView
    {
        $snapshots = $this->catalog->forStore(
            $storeId,
            array_values(array_map(static fn (MenuItem $item): string => $item->variantId, $menu->items)),
        );

        $views = [];

        foreach ($menu->items as $item) {
            $snapshot = $snapshots[$item->variantId] ?? null;

            if ($snapshot === null || ! $snapshot->isListable) {
                continue;
            }

            $views[] = new MenuItemView($item, $snapshot);
        }

        return new MenuView($menu, $views);
    }

    /**
     * The same for several menus, still in one catalog call.
     *
     * @param  list<Menu>  $menus
     * @return list<MenuView>
     */
    public function forMenus(string $storeId, array $menus): array
    {
        $variantIds = [];

        foreach ($menus as $menu) {
            foreach ($menu->items as $item) {
                $variantIds[] = $item->variantId;
            }
        }

        $snapshots = $this->catalog->forStore($storeId, array_values(array_unique($variantIds)));
        $views = [];

        foreach ($menus as $menu) {
            $items = [];

            foreach ($menu->items as $item) {
                $snapshot = $snapshots[$item->variantId] ?? null;

                if ($snapshot === null || ! $snapshot->isListable) {
                    continue;
                }

                $items[] = new MenuItemView($item, $snapshot);
            }

            $views[] = new MenuView($menu, $items);
        }

        return $views;
    }
}
