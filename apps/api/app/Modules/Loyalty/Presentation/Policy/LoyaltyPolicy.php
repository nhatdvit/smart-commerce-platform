<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Policy;

use App\Modules\Identity\Application\Contract\ChecksStorePermissions;
use App\Modules\Loyalty\Domain\Entity\Reward;
use App\Modules\Store\Application\StoreContext;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who may read, manage and redeem the loyalty program.
 *
 * Registered against the `Reward` entity because it is the one domain object
 * this module has (CLAUDE.md section 16); the dashboard, member and settings
 * endpoints authorize against the same policy by class, not instance, the
 * same way AdminCouponController checks `viewAny` and `create` against
 * `Coupon::class` before any row exists to check.
 *
 * Three grants, not one: `loyalty.read` is everyday visibility (a cashier
 * looking up a member's balance), `loyalty.redeem` is spending points at the
 * counter (also a cashier's job), and `loyalty.manage` is deciding the
 * program itself - the reward catalogue and the earning rate - which stays
 * with the owner and the manager, the same split `promotion.*` already draws
 * for discount codes (see RolePermissionSeeder).
 */
final readonly class LoyaltyPolicy
{
    public function __construct(
        private ChecksStorePermissions $permissions,
        private StoreContext $storeContext,
    ) {}

    public function viewAny(Authenticatable $user): bool
    {
        return $this->allows($user, 'loyalty.read');
    }

    public function create(Authenticatable $user): bool
    {
        return $this->allows($user, 'loyalty.manage');
    }

    public function update(Authenticatable $user, Reward $reward): bool
    {
        return $this->allows($user, 'loyalty.manage') && $reward->storeId === $this->storeContext->id();
    }

    public function redeem(Authenticatable $user): bool
    {
        return $this->allows($user, 'loyalty.redeem');
    }

    public function manageSettings(Authenticatable $user): bool
    {
        return $this->allows($user, 'loyalty.manage');
    }

    private function allows(Authenticatable $user, string $permission): bool
    {
        $storeId = $this->storeContext->id();

        return $storeId !== null && $this->permissions->allows($user, $permission, $storeId);
    }
}
