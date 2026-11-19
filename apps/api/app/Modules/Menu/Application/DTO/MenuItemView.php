<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application\DTO;

use App\Modules\Catalog\Application\DTO\VariantSnapshot;
use App\Modules\Menu\Domain\Entity\MenuItem;

/** A menu item together with the catalog facts needed to show it. */
final readonly class MenuItemView
{
    public function __construct(
        public MenuItem $item,
        public VariantSnapshot $variant,
    ) {}
}
