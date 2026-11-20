<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application\DTO;

use App\Modules\Menu\Domain\Enum\MenuStatus;

/**
 * A whole menu as the caller wants it to be.
 *
 * A full replacement rather than a patch, as designed: a menu is a set, and
 * "these are the items" is both easier to reason about and safer than a
 * sequence of partial edits that could leave it in a state nobody intended.
 */
final readonly class MenuInput
{
    /**
     * @param  list<MenuItemInput>  $items
     */
    public function __construct(
        public string $name,
        public MenuStatus $status,
        public array $items,
    ) {}
}
