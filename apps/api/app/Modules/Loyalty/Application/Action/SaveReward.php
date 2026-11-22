<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Action;

use App\Modules\Loyalty\Application\DTO\RewardInput;
use App\Modules\Loyalty\Domain\Contract\RewardRepository;
use App\Modules\Loyalty\Domain\Entity\Reward;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;

/**
 * Creates a reward when `rewardId` is absent, edits one when it is there -
 * the same create-or-edit shape
 * {@see \App\Modules\Payment\Application\Action\UpdateBankAccount} uses for a
 * single settings-like row, applied here to a whole catalogue instead.
 */
final readonly class SaveReward
{
    public function __construct(
        private RewardRepository $rewards,
        private RecordsAuditTrail $audit,
    ) {}

    public function handle(string $storeId, ?string $rewardId, RewardInput $input, string $actorId): Reward
    {
        $existing = $rewardId === null ? null : $this->rewards->findInStore($storeId, $rewardId);

        if ($rewardId !== null && $existing === null) {
            throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Reward not found');
        }

        $reward = Reward::create(
            $rewardId,
            $storeId,
            $input->name,
            $input->pointsCost,
            $input->icon,
            $input->isActive,
        );

        $saved = $this->rewards->save($reward);

        $this->audit->record(
            AuditEntry::for(
                $rewardId === null ? 'loyalty.reward.create' : 'loyalty.reward.update',
                'loyalty_reward',
                (string) $saved->id,
                [
                    'name' => ['from' => $existing?->name, 'to' => $saved->name],
                    'points_cost' => ['from' => $existing?->pointsCost, 'to' => $saved->pointsCost],
                    'is_active' => ['from' => $existing?->isActive, 'to' => $saved->isActive],
                ],
            )->inStore($storeId)->by($actorId),
        );

        return $saved;
    }
}
