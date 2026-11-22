<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Http\Resource;

use App\Modules\Loyalty\Domain\ValueObject\LoyaltySettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `LoyaltySettings` schema.
 *
 * @property-read LoyaltySettings $resource
 */
final class LoyaltySettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $settings = $this->resource;

        return [
            'store_id' => $settings->storeId,
            'points_per_currency' => $settings->pointsPerCurrency,
            'loyal_tier_threshold_points' => $settings->loyalTierThresholdPoints,
        ];
    }
}
