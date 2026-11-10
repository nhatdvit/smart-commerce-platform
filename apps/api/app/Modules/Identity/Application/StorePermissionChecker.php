<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Application\Contract\ChecksStorePermissions;
use App\Modules\Identity\Domain\Enum\MembershipStatus;
use App\Modules\Identity\Domain\Enum\RoleScope;
use App\Modules\Identity\Infrastructure\Persistence\Model\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether a user may do something in a given store.
 *
 * Two independent grants, both honoured:
 *
 *   - a GLOBAL role (SUPER_ADMIN) applies to every store;
 *   - a STORE role applies only through an ACTIVE membership of that store.
 *
 * Checking the role alone would let a cashier at store A act on store B, which
 * is the single most damaging authorization bug this system could ship
 * (CLAUDE.md §8). A blocked user is refused regardless of roles.
 *
 * Results are memoised per (user, store) for the lifetime of the request, so a
 * handler that checks several permissions still costs one query.
 */
final class StorePermissionChecker implements ChecksStorePermissions
{
    /** @var array<string, list<string>> */
    private array $cache = [];

    public function allows(Authenticatable $user, string $permission, string $storeId): bool
    {
        return in_array($permission, $this->permissionsFor($user, $storeId), true);
    }

    /** @return list<string> */
    public function permissionsFor(Authenticatable $user, string $storeId): array
    {
        if (! $user instanceof User) {
            return [];
        }

        if (! $user->status->canAuthenticate()) {
            return [];
        }

        $key = $user->id.'|'.$storeId;

        return $this->cache[$key] ??= $this->load($user->id, $storeId);
    }

    /** @return list<string> */
    private function load(string $userId, string $storeId): array
    {
        // Platform-wide grants: user_roles, restricted to GLOBAL roles so a
        // store role accidentally attached there cannot widen into every store.
        $global = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('user_roles.user_id', $userId)
            ->where('roles.scope', RoleScope::Global->value)
            ->pluck('permissions.code');

        // Store-scoped grants: only through an active membership of THIS store.
        $scoped = DB::table('store_members')
            ->join('roles', 'roles.id', '=', 'store_members.role_id')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('store_members.user_id', $userId)
            ->where('store_members.store_id', $storeId)
            ->where('store_members.status', MembershipStatus::Active->value)
            ->pluck('permissions.code');

        return array_values(array_unique([
            ...$global->all(),
            ...$scoped->all(),
        ]));
    }
}
