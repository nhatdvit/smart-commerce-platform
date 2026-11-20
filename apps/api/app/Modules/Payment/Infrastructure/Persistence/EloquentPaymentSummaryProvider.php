<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence;

use App\Modules\Payment\Application\Contract\ProvidesPaymentSummaries;
use App\Modules\Payment\Application\DTO\PaymentSnapshot;
use App\Modules\Payment\Infrastructure\Persistence\Model\Payment as PaymentModel;
use App\Shared\Domain\ValueObject\Money;

/**
 * The exported read model, over `payments`.
 *
 * One query for a whole page of orders, never one per order: the operations
 * queue would otherwise be an N+1 that only shows up under real traffic.
 *
 * Store-scoped like every other read here. The composite foreign key means a
 * payment cannot reference another store's order, and this `where` means a
 * caller cannot read one anyway (CLAUDE.md section 8).
 */
final class EloquentPaymentSummaryProvider implements ProvidesPaymentSummaries
{
    public function forOrders(string $storeId, array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $snapshots = [];

        $rows = PaymentModel::query()
            ->where('store_id', $storeId)
            ->whereIn('order_id', array_values(array_unique($orderIds)))
            ->get();

        foreach ($rows as $row) {
            $snapshots[$row->order_id] = new PaymentSnapshot(
                $row->id,
                $row->order_id,
                $row->method,
                $row->status,
                Money::vnd($row->amount),
                $row->expires_at,
                $row->paid_at,
            );
        }

        return $snapshots;
    }
}
