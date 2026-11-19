<?php

declare(strict_types=1);

namespace App\Modules\Menu\Domain\Enum;

/**
 * Lifecycle of a menu.
 *
 * A DRAFT menu can be built up without anything becoming sellable, which is
 * the point of separating menus from the catalog at all: nothing is on sale
 * until a menu says it is (CLAUDE.md section 6).
 */
enum MenuStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Archived = 'ARCHIVED';

    /** Whether this menu may serve customers. */
    public function isServable(): bool
    {
        return $this === self::Active;
    }
}
