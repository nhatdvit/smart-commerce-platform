<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Policy;

use App\Modules\Identity\Application\Contract\ChecksStorePermissions;
use App\Modules\Payment\Domain\Entity\Payment;
use App\Modules\Store\Application\StoreContext;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who may read payments, confirm or flag one, and who may touch where the
 * money goes.
 *
 * Both halves of the rule every time: the role has to carry the permission,
 * and the grant has to apply to the store this request resolved to (CLAUDE.md
 * section 8). The instance methods additionally check the payment in hand
 * belongs to that store - the check a route-level guard cannot make.
 *
 * `manageBankAccount` and `updateMethods` take no instance, the same way
 * `MenuPolicy::updateAvailability` does: there is no existing object to check
 * against when the account may not be configured yet.
 *
 * `payment.bank-account.manage` is deliberately an owner-level grant -
 * `STORE_MANAGER` does not hold it, because changing where money lands is not
 * a day-to-day operational action (see RolePermissionSeeder). The read side
 * carries the same grant: a masked account number is still enough to know
 * which bank a store uses, which is not every role's business to see either.
 */
final readonly class PaymentPolicy
{
    public function __construct(
        private ChecksStorePermissions $permissions,
        private StoreContext $storeContext,
    ) {}

    public function viewAny(Authenticatable $user): bool
    {
        return $this->allows($user, 'payment.read');
    }

    public function view(Authenticatable $user, Payment $payment): bool
    {
        return $this->allows($user, 'payment.read') && $this->belongsToThisStore($payment);
    }

    public function confirm(Authenticatable $user, Payment $payment): bool
    {
        return $this->allows($user, 'payment.confirm') && $this->belongsToThisStore($payment);
    }

    public function flag(Authenticatable $user, Payment $payment): bool
    {
        return $this->allows($user, 'payment.confirm') && $this->belongsToThisStore($payment);
    }

    public function manageBankAccount(Authenticatable $user): bool
    {
        return $this->allows($user, 'payment.bank-account.manage');
    }

    public function updateMethods(Authenticatable $user): bool
    {
        return $this->allows($user, 'store.update');
    }

    private function belongsToThisStore(Payment $payment): bool
    {
        return $payment->storeId === $this->storeContext->id();
    }

    private function allows(Authenticatable $user, string $permission): bool
    {
        $storeId = $this->storeContext->id();

        return $storeId !== null && $this->permissions->allows($user, $permission, $storeId);
    }
}
