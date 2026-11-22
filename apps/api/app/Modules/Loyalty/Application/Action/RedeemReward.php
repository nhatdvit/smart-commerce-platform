<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Action;

use App\Modules\Loyalty\Domain\Contract\LoyaltyLedger;
use App\Modules\Loyalty\Domain\Contract\RewardRepository;
use App\Modules\Loyalty\Domain\Entity\LedgerEntry;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Spends a member's points on a reward.
 *
 * There is no customer-facing redemption flow yet (CLAUDE.md delivery stage
 * 4), so this is always a staff member acting on a member's behalf - handing
 * over the coffee first, then recording it here, the same trust model cash
 * collection already has. `actorId` is required for exactly that reason: a
 * redemption with nobody accountable for it is not a fact worth recording.
 */
final readonly class RedeemReward
{
    public function __construct(
        private LoyaltyLedger $ledger,
        private RewardRepository $rewards,
        private RecordsAuditTrail $audit,
    ) {}

    public function handle(
        string $storeId,
        string $phone,
        string $rewardId,
        string $actorId,
        ?string $note,
    ): LedgerEntry {
        return DB::transaction(function () use ($storeId, $phone, $rewardId, $actorId, $note): LedgerEntry {
            $reward = $this->rewards->findInStore($storeId, $rewardId);

            if ($reward === null) {
                throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Reward not found');
            }

            if (! $reward->isActive) {
                throw new BusinessRuleViolation(
                    ErrorCode::InvalidStateTransition,
                    'This reward is no longer offered',
                    [['field' => 'reward_id', 'message' => 'Quà này đã ngừng áp dụng.']],
                );
            }

            $balance = $this->ledger->balanceOf($storeId, $phone);

            if ($balance['balance'] < $reward->pointsCost) {
                throw new BusinessRuleViolation(
                    ErrorCode::ValidationFailed,
                    sprintf(
                        'This member has %d points, which is fewer than the %d this reward costs',
                        $balance['balance'],
                        $reward->pointsCost,
                    ),
                    [[
                        'field' => 'reward_id',
                        'message' => sprintf(
                            'Khách chỉ còn %d điểm, không đủ %d điểm để đổi quà này.',
                            $balance['balance'],
                            $reward->pointsCost,
                        ),
                    ]],
                );
            }

            $entry = $this->ledger->recordRedeem(
                $storeId,
                $phone,
                $rewardId,
                $reward->pointsCost,
                $actorId,
                $note,
            );

            $this->audit->record(
                AuditEntry::for('loyalty.reward.redeem', 'loyalty_member', $phone, [
                    'reward_id' => $rewardId,
                    'reward_name' => $reward->name,
                    'points' => $reward->pointsCost,
                    'note' => $note,
                ])->inStore($storeId)->by($actorId),
            );

            return $entry;
        });
    }
}
