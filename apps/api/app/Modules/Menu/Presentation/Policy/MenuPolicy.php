<?php

declare(strict_types=1);

namespace App\Modules\Menu\Presentation\Policy;

use App\Modules\Identity\Application\Contract\ChecksStorePermissions;
use App\Modules\Menu\Domain\Entity\Menu;
use App\Modules\Store\Application\StoreContext;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who may read and change a menu.
 *
 * Both halves of the rule every time: the role has to carry the permission,
 * and the grant has to apply to the store this request resolved to (CLAUDE.md
 * section 8). `update` additionally checks the menu in hand belongs to that
 * store, which is the check a route-level guard cannot make.
 *
 * `updateAvailability` is the one place a kitchen permission is enough. Marking
 * an item sold out is the most-used action in a working kitchen and must not
 * need a manager, which is exactly what the designed contract says.
 */
final readonly class MenuPolicy
{
    public function __construct(
        private ChecksStorePermissions $permissions,
        private StoreContext $storeContext,
    ) {}

    public function viewAny(Authenticatable $user): bool
    {
        return $this->allows($user, 'menu.read');
    }

    public function view(Authenticatable $user, Menu $menu): bool
    {
        return $this->allows($user, 'menu.read') && $menu->storeId === $this->storeContext->id();
    }

    public function update(Authenticatable $user, Menu $menu): bool
    {
        return $this->allows($user, 'menu.manage') && $menu->storeId === $this->storeContext->id();
    }

    /** Store scope is enforced by the store-scoped lookup of the item itself. */
    public function updateAvailability(Authenticatable $user): bool
    {
        return $this->allows($user, 'menu.manage') || $this->allows($user, 'kitchen.operate');
    }

    private function allows(Authenticatable $user, string $permission): bool
    {
        $storeId = $this->storeContext->id();

        return $storeId !== null && $this->permissions->allows($user, $permission, $storeId);
    }
}
