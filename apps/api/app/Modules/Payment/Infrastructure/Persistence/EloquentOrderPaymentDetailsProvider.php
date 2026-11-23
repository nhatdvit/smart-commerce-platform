<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence;

use App\Modules\Payment\Application\Contract\ProvidesOrderPaymentDetails;
use App\Modules\Payment\Application\DTO\CustomerPaymentView;
use App\Modules\Payment\Domain\ValueObject\KnownBank;
use App\Modules\Payment\Infrastructure\Persistence\Model\Payment as PaymentModel;
use App\Shared\Domain\ValueObject\Money;

/**
 * The exported customer-facing read model, over `payments`.
 *
 * Store-scoped like every other read here: the composite foreign key means a
 * payment cannot reference another store's order, and this `where` means a
 * caller cannot read one anyway (CLAUDE.md section 8).
 */
final class EloquentOrderPaymentDetailsProvider implements ProvidesOrderPaymentDetails
{
    public function forOrder(string $storeId, string $orderId): ?CustomerPaymentView
    {
        $row = PaymentModel::query()
            ->where('store_id', $storeId)
            ->where('order_id', $orderId)
            ->first();

        if ($row === null) {
            return null;
        }

        return new CustomerPaymentView(
            $row->id,
            $row->method,
            $row->status,
            Money::vnd($row->amount),
            $row->expires_at,
            $row->paid_at,
            $row->transfer_reference,
            $row->qr_payload,
            $row->bank_bin,
            $row->bank_bin === null ? null : KnownBank::nameFor($row->bank_bin),
            $row->bank_account_number,
            $row->bank_account_name,
            $row->customer_reported_at,
        );
    }
}
