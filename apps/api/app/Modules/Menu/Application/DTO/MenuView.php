<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application\DTO;

use App\Modules\Menu\Domain\Entity\Menu;

/**
 * A menu with each item's catalog facts resolved, ready to be rendered.
 *
 * `items` holds only what is actually offered: an item whose product has been
 * archived is left out, because "retired" has to mean retired everywhere, not
 * just on the screen that retired it.
 */
final readonly class MenuView
{
    /**
     * @param  list<MenuItemView>  $items
     */
    public function __construct(
        public Menu $menu,
        public array $items,
    ) {}
}
