<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Infrastructure\Persistence;

use App\Modules\Loyalty\Domain\Contract\LoyaltySettingsRepository;
use App\Modules\Loyalty\Domain\ValueObject\LoyaltySettings;
use App\Modules\Loyalty\Infrastructure\Persistence\Model\LoyaltySettingsModel;

final class EloquentLoyaltySettingsRepository implements LoyaltySettingsRepository
{
    public function forStore(string $storeId): LoyaltySettings
    {
        $row = LoyaltySettingsModel::query()->find($storeId);

        return $row === null
            ? LoyaltySettings::default($storeId)
            : LoyaltySettings::create($storeId, $row->points_per_currency, $row->loyal_tier_threshold_points);
    }

    public function save(LoyaltySettings $settings): LoyaltySettings
    {
        LoyaltySettingsModel::query()->updateOrCreate(
            ['store_id' => $settings->storeId],
            [
                'points_per_currency' => $settings->pointsPerCurrency,
                'loyal_tier_threshold_points' => $settings->loyalTierThresholdPoints,
            ],
        );

        return $settings;
    }
}
