<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Enum;

/**
 * Where a role applies.
 *
 * GLOBAL roles are platform-wide and are held through user_roles.
 * STORE roles are granted per store through store_members, so the same user can
 * be a manager of one store and have no access at all to another.
 */
enum RoleScope: string
{
    case Global = 'GLOBAL';
    case Store = 'STORE';
}
