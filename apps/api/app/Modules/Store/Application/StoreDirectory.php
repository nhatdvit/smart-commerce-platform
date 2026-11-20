<?php

declare(strict_types=1);

namespace App\Modules\Store\Application;

use App\Modules\Store\Infrastructure\Persistence\Model\Store;

/**
 * Store names by id, for modules that may not read the stores table.
 *
 * Exported on purpose: Identity has to name the stores a user staffs, and the
 * alternative - a join from another module into this one's table - is the
 * coupling CLAUDE.md section 3 forbids. One query for a whole set of ids.
 */
final readonly class StoreDirectory
{
    /**
     * @param  list<string>  $storeIds
     * @return array<string, string> store id => display name
     */
    public function namesByIds(array $storeIds): array
    {
        if ($storeIds === []) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = Store::query()
            ->whereIn('id', array_values(array_unique($storeIds)))
            ->pluck('name', 'id')
            ->all();

        return $names;
    }
}
