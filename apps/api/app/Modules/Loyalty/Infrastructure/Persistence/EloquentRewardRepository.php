<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Infrastructure\Persistence;

use App\Modules\Loyalty\Domain\Contract\RewardRepository;
use App\Modules\Loyalty\Domain\Entity\Reward;
use App\Modules\Loyalty\Infrastructure\Persistence\Model\RewardModel;

final class EloquentRewardRepository implements RewardRepository
{
    public function allInStore(string $storeId): array
    {
        $rows = RewardModel::query()
            ->where('store_id', $storeId)
            // How many times each has ever been redeemed - the relation is
            // inherently REDEEM-only, since an EARN row's reward_id is always
            // null (the migration's own CHECK constraint).
            ->withCount('redemptions')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (RewardModel $row): Reward => $this->toEntity($row))
            ->all();

        return array_values($rows);
    }

    public function findInStore(string $storeId, string $rewardId): ?Reward
    {
        $row = RewardModel::query()
            ->where('store_id', $storeId)
            ->withCount('redemptions')
            ->whereKey($rewardId)
            ->first();

        return $row === null ? null : $this->toEntity($row);
    }

    public function save(Reward $reward): Reward
    {
        $row = $reward->id === null ? new RewardModel : RewardModel::query()
            ->where('store_id', $reward->storeId)
            ->whereKey($reward->id)
            ->sole();

        $row->fill([
            'store_id' => $reward->storeId,
            'name' => $reward->name,
            'points_cost' => $reward->pointsCost,
            'icon' => $reward->icon,
            'is_active' => $reward->isActive,
        ])->save();

        return $this->toEntity($row->refresh());
    }

    private function toEntity(RewardModel $row): Reward
    {
        /** @var int|null $redeemedCount */
        $redeemedCount = $row->redemptions_count ?? null;

        return Reward::create(
            $row->id,
            $row->store_id,
            $row->name,
            $row->points_cost,
            $row->icon,
            $row->is_active,
            $redeemedCount === null ? null : (int) $redeemedCount,
        );
    }
}
