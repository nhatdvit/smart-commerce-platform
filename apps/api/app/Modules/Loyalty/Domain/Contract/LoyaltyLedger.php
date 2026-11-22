<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Contract;

use App\Modules\Loyalty\Domain\Criteria\MemberCriteria;
use App\Modules\Loyalty\Domain\Entity\LedgerEntry;
use App\Modules\Loyalty\Domain\Entity\LoyaltyMember;

/**
 * Persistence seam for the points ledger: who has earned what, and what has
 * been redeemed.
 *
 * Store-scoped throughout, the same convention as every other repository in
 * this application (CLAUDE.md section 8).
 */
interface LoyaltyLedger
{
    /** @return array{items: list<LoyaltyMember>, next_cursor: string|null, has_more: bool} */
    public function paginateMembers(string $storeId, MemberCriteria $criteria): array;

    /** @return array{lifetimeEarnedPoints: int, balance: int} */
    public function balanceOf(string $storeId, string $phone): array;

    /**
     * This phone's entries, most recent first.
     *
     * @return list<LedgerEntry>
     */
    public function historyFor(string $storeId, string $phone): array;

    /**
     * PAID payments this store has not yet awarded points for - what the
     * earning sync reads before writing anything.
     *
     * @return list<array{paymentId: string, phone: string, amount: int}>
     */
    public function unsyncedPaidPayments(string $storeId): array;

    /**
     * Awards points for one payment. Idempotent on `payment_id` by the
     * migration's own unique index - a second call for the same payment is a
     * silent no-op, never a second award.
     */
    public function recordEarn(string $storeId, string $phone, string $paymentId, int $points): void;

    /** Spends points on a reward. The caller has already checked the balance covers it. */
    public function recordRedeem(
        string $storeId,
        string $phone,
        string $rewardId,
        int $points,
        string $actorId,
        ?string $note,
    ): LedgerEntry;

    /** @return array{members: int, pointsIssued: int, redemptionsThisMonth: int} */
    public function summary(string $storeId): array;
}
