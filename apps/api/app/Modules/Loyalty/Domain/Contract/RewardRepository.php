<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Contract;

use App\Modules\Loyalty\Domain\Entity\Reward;

/** Persistence seam for the redeemable-reward catalogue. */
interface RewardRepository
{
    /**
     * Active and inactive, most recently created first - this is the
     * catalogue to manage, not to browse.
     *
     * @return list<Reward>
     */
    public function allInStore(string $storeId): array;

    public function findInStore(string $storeId, string $rewardId): ?Reward;

    public function save(Reward $reward): Reward;
}
