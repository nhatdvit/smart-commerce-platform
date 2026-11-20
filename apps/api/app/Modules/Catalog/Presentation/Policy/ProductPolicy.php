<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Policy;

use App\Modules\Catalog\Domain\Entity\Product;
use App\Modules\Identity\Application\Contract\ChecksStorePermissions;
use App\Modules\Store\Application\StoreContext;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who may read and change catalog master data.
 *
 * Both halves of the rule, every time (CLAUDE.md section 8):
 *
 *   1. the role has to carry the permission, and
 *   2. the grant has to apply to the store this request resolved to.
 *
 * The `store.can` middleware on the route checks the same pair before a
 * controller runs. This is not redundancy for its own sake: the middleware
 * guards the ROUTE, the policy guards the OBJECT, and the object check is the
 * one that catches a product id from another store arriving on a route the
 * caller is otherwise allowed to use.
 */
final readonly class ProductPolicy
{
    public function __construct(
        private ChecksStorePermissions $permissions,
        private StoreContext $storeContext,
    ) {}

    public function viewAny(Authenticatable $user): bool
    {
        return $this->allows($user, 'catalog.read');
    }

    public function create(Authenticatable $user): bool
    {
        return $this->allows($user, 'catalog.manage');
    }

    public function update(Authenticatable $user, Product $product): bool
    {
        return $this->allows($user, 'catalog.manage')
            && $product->storeId === $this->storeContext->id();
    }

    private function allows(Authenticatable $user, string $permission): bool
    {
        $storeId = $this->storeContext->id();

        // No store context means the route is misconfigured. Refusing is the
        // only safe reading: a permission with no store to apply to is not a
        // permission.
        return $storeId !== null && $this->permissions->allows($user, $permission, $storeId);
    }
}
