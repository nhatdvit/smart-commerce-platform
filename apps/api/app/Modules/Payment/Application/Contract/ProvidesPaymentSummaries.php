<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Contract;

use App\Modules\Payment\Application\DTO\PaymentSnapshot;

/**
 * Payment's exported read model, and the only way into it from outside.
 *
 * Other modules depend on this application service instead of the payments
 * table, so the payment schema stays private to the Payment module and can
 * grow the bank snapshot, the transfer reference and the reconciliation
 * columns without touching anything else (CLAUDE.md section 3).
 *
 * Read-only on purpose. Marking money as received is a permissioned, audited
 * operation inside this module; no other module may do it, and this contract
 * offers no way to.
 */
interface ProvidesPaymentSummaries
{
    /**
     * Payments for the given orders of one store, keyed by ORDER id.
     *
     * Keyed by order rather than payment because that is the question callers
     * ask: "how is this order being paid". An order with no payment row is
     * simply absent - the caller decides what that means. For the operations
     * queue it means "not recorded yet", which is shown as such rather than
     * guessed at.
     *
     * @param  list<string>  $orderIds
     * @return array<string, PaymentSnapshot>
     */
    public function forOrders(string $storeId, array $orderIds): array;
}
