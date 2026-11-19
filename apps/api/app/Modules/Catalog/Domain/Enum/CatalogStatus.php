<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Enum;

/**
 * Master-data lifecycle for a product or a variant.
 *
 * ARCHIVED is how catalog master data is removed. Nothing is hard-deleted: an
 * order line that referenced a product keeps its snapshot, and the product it
 * came from has to remain readable for history to make sense (CLAUDE.md
 * sections 6 and 7).
 */
enum CatalogStatus: string
{
    /** Being prepared. Visible to staff, never sellable. */
    case Draft = 'DRAFT';

    /** Available to be put on a menu. */
    case Active = 'ACTIVE';

    /** Retired. Kept for history, never sellable again. */
    case Archived = 'ARCHIVED';

    /** Whether a menu may offer this. A menu still decides when and at what price. */
    public function isListable(): bool
    {
        return $this === self::Active;
    }

    /** Whether this row has been retired, which is what "deleted" means here. */
    public function isArchived(): bool
    {
        return $this === self::Archived;
    }
}
