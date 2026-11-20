<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Policy;

use App\Modules\Identity\Application\Contract\ChecksStorePermissions;
use App\Modules\Order\Domain\Entity\Order;
use App\Modules\Order\Domain\Enum\OrderStatus;
use App\Modules\Store\Application\StoreContext;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who may read an order and who may move it.
 *
 * Both halves of the rule every time: the role has to carry the permission,
 * and the grant has to apply to the store this request resolved to (CLAUDE.md
 * section 8). `view` and `transition` additionally check that the order in
 * hand belongs to that store - the check a route-level guard cannot make.
 *
 * The kitchen rule is the interesting one. `kitchen.operate` alone is enough
 * to reach PREPARING and READY, because that is what a cook does all morning
 * and waiting for a manager would stop service. It is NOT enough to complete
 * an order or to cancel one: those touch the money and the customer, and
 * KITCHEN_STAFF deliberately holds no payment or order-transition grant
 * (see RolePermissionSeeder).
 */
final readonly class OrderPolicy
{
    public function __construct(
        private ChecksStorePermissions $permissions,
        private StoreContext $storeContext,
    ) {}

    public function viewAny(Authenticatable $user): bool
    {
        return $this->allows($user, 'order.read');
    }

    public function view(Authenticatable $user, Order $order): bool
    {
        return $this->allows($user, 'order.read') && $this->belongsToThisStore($order);
    }

    public function transition(Authenticatable $user, Order $order, OrderStatus $to): bool
    {
        if (! $this->belongsToThisStore($order)) {
            return false;
        }

        if ($this->allows($user, 'order.transition')) {
            return true;
        }

        return $to->isKitchenStep() && $this->allows($user, 'kitchen.operate');
    }

    private function belongsToThisStore(Order $order): bool
    {
        return $order->storeId === $this->storeContext->id();
    }

    private function allows(Authenticatable $user, string $permission): bool
    {
        $storeId = $this->storeContext->id();

        return $storeId !== null && $this->permissions->allows($user, $permission, $storeId);
    }
}
