<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Http\Resource;

use App\Modules\Loyalty\Domain\Entity\Reward;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `Reward` schema.
 *
 * @property-read Reward $resource
 */
final class RewardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $reward = $this->resource;

        return [
            'id' => $reward->id,
            'store_id' => $reward->storeId,
            'name' => $reward->name,
            'points_cost' => $reward->pointsCost,
            'icon' => $reward->icon,
            'is_active' => $reward->isActive,
            'redeemed_count' => $reward->redeemedCount ?? 0,
        ];
    }
}
