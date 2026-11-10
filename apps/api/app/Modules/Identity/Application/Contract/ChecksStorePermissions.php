<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Contract;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The authorization seam between Identity and every other module.
 *
 * Other modules depend on this contract, never on the users, roles or
 * store_members tables (CLAUDE.md §3). Identity binds the implementation in its
 * service provider.
 */
interface ChecksStorePermissions
{
    /**
     * May this user exercise this permission in this store?
     *
     * Returns true only when BOTH hold: the permission is granted by a role the
     * user holds, and that grant applies to this specific store — either
     * through an active membership of it, or through a platform-wide role.
     */
    public function allows(Authenticatable $user, string $permission, string $storeId): bool;

    /**
     * Every permission code effective for this user in this store.
     *
     * @return list<string>
     */
    public function permissionsFor(Authenticatable $user, string $storeId): array;
}
