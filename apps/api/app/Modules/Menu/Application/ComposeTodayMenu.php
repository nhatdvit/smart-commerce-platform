<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application;

use App\Modules\Menu\Application\DTO\MenuView;
use App\Modules\Menu\Domain\Contract\MenuRepository;
use App\Modules\Menu\Domain\Entity\Menu;

/**
 * What a customer sees when they open the storefront: the store's live menu.
 *
 * "Today" is simply "whichever menu is ACTIVE" in this milestone - recurring
 * weekday schedules, time windows and date exceptions are part of the designed
 * `TodayMenu` contract (`docs/api/openapi.yaml`) but not yet built (see the
 * note on {@see Menu}), so there is no narrower question to ask yet than "is
 * this menu on". A store is provisioned with exactly one menu
 * (`DefaultMenuSeeder`), so picking the first servable one is correct today and
 * stays correct once a store can run more than one.
 */
final readonly class ComposeTodayMenu
{
    public function __construct(
        private MenuRepository $menus,
        private ComposeMenuView $composer,
    ) {}

    public function forStore(string $storeId): ?MenuView
    {
        $servable = array_values(array_filter(
            $this->menus->allInStore($storeId),
            static fn (Menu $menu): bool => $menu->status->isServable(),
        ));

        if ($servable === []) {
            return null;
        }

        return $this->composer->forMenu($storeId, $servable[0]);
    }
}
