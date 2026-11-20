<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Persistence\Model\User;

/**
 * Account names by id, for modules that may not read the users table.
 *
 * Exported on purpose, the same way Store exports its directory: an order's
 * status history has to name whoever moved the order, and the alternative - a
 * join from the Order module into this one's table - is the coupling
 * CLAUDE.md section 3 forbids. One query for a whole set of ids.
 *
 * Names only. Nothing here exposes an email, a phone number or a password
 * hash: a history row needs to say "Demo Manager", not everything known about
 * that account (section 8).
 */
final readonly class StaffDirectory
{
    /**
     * @param  list<string>  $userIds
     * @return array<string, string> user id => full name
     */
    public function namesByIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = User::query()
            ->whereIn('id', array_values(array_unique($userIds)))
            ->pluck('full_name', 'id')
            ->all();

        return $names;
    }
}
