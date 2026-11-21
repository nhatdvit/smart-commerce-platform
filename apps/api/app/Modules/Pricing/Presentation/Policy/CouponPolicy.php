<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Presentation\Policy;

use App\Modules\Identity\Application\Contract\ChecksStorePermissions;
use App\Modules\Pricing\Domain\Entity\Coupon;
use App\Modules\Store\Application\StoreContext;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who may read and change discount codes.
 *
 * Both halves of the rule every time: the role has to carry the permission,
 * and the grant has to apply to the store this request resolved to (CLAUDE.md
 * section 8). `update` additionally checks the coupon in hand belongs to that
 * store - the check a route-level guard cannot make.
 *
 * A coupon is money the shop gives away, so `promotion.manage` sits with the
 * owner and the manager; a cashier may not invent discounts, and the kitchen
 * has no business here at all (see RolePermissionSeeder).
 */
final readonly class CouponPolicy
{
    public function __construct(
        private ChecksStorePermissions $permissions,
        private StoreContext $storeContext,
    ) {}

    public function viewAny(Authenticatable $user): bool
    {
        return $this->allows($user, 'promotion.read');
    }

    public function create(Authenticatable $user): bool
    {
        return $this->allows($user, 'promotion.manage');
    }

    public function update(Authenticatable $user, Coupon $coupon): bool
    {
        return $this->allows($user, 'promotion.manage')
            && $coupon->storeId === $this->storeContext->id();
    }

    private function allows(Authenticatable $user, string $permission): bool
    {
        $storeId = $this->storeContext->id();

        return $storeId !== null && $this->permissions->allows($user, $permission, $storeId);
    }
}
