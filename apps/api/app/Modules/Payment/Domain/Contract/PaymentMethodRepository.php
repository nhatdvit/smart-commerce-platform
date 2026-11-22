<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contract;

use App\Modules\Payment\Domain\Enum\PaymentMethod;

/**
 * Persistence seam for `payment_methods`: the per-store on/off switches.
 *
 * Deliberately returns and accepts a plain map rather than entities - there is
 * no behaviour here beyond "is this one enabled". Both MVP methods are
 * provisioned, enabled, for every existing store by the migration that creates
 * this table (the same reference-data pattern as `DefaultMenuSeeder`
 * provisioning a menu), so there is nothing a richer domain object would add.
 */
interface PaymentMethodRepository
{
    /** @return array<string, bool> PaymentMethod value => enabled, every MVP method present */
    public function forStore(string $storeId): array;

    /**
     * Replaces the store's enabled set.
     *
     * @param  array<string, bool>  $enabled  PaymentMethod value => enabled
     * @return array<string, bool>
     */
    public function setForStore(string $storeId, array $enabled): array;
}
