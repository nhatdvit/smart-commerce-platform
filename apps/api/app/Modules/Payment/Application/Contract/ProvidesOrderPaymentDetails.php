<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Contract;

use App\Modules\Payment\Application\DTO\CustomerPaymentView;

/**
 * Payment's exported read model for `GET /orders/{order}/payment`.
 *
 * Separate from {@see ProvidesPaymentSummaries}: that contract is what the
 * operations queue reads for every order on a page, deliberately without the
 * bank account snapshot or the VietQR payload. A customer reading their own
 * one order needs exactly that detail to pay it, which is the entire reason
 * this contract exists instead of widening the thin one.
 */
interface ProvidesOrderPaymentDetails
{
    /** Null when the order has no payment row at all - a real anomaly, not "not found". */
    public function forOrder(string $storeId, string $orderId): ?CustomerPaymentView;
}
