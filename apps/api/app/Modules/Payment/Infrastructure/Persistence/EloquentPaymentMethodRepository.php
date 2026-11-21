<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence;

use App\Modules\Payment\Domain\Contract\PaymentMethodRepository;
use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Modules\Payment\Infrastructure\Persistence\Model\PaymentMethodSetting;

/**
 * Eloquent implementation of the payment-method switches.
 *
 * `forStore()` always answers with both MVP methods present, defaulting an
 * unprovisioned one to disabled rather than silently omitting it - a store
 * created by some future path that forgot to provision these rows must not
 * read as "transfer is enabled" by the absence of a row saying otherwise.
 */
final class EloquentPaymentMethodRepository implements PaymentMethodRepository
{
    public function forStore(string $storeId): array
    {
        $rows = PaymentMethodSetting::query()
            ->where('store_id', $storeId)
            ->get()
            ->keyBy(static fn (PaymentMethodSetting $row): string => $row->method->value);

        $result = [];

        foreach (PaymentMethod::cases() as $method) {
            $row = $rows->get($method->value);
            $result[$method->value] = $row instanceof PaymentMethodSetting && $row->enabled;
        }

        return $result;
    }

    public function setForStore(string $storeId, array $enabled): array
    {
        foreach (PaymentMethod::cases() as $method) {
            PaymentMethodSetting::query()->updateOrCreate(
                ['store_id' => $storeId, 'method' => $method->value],
                ['enabled' => $enabled[$method->value] ?? false],
            );
        }

        return $this->forStore($storeId);
    }
}
