<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Entity;

use App\Modules\Loyalty\Domain\Enum\MembershipTier;

/**
 * One member, as their ledger adds up - a pure read projection, the same
 * shape of answer {@see \App\Modules\Order\Domain\Entity\CustomerSummary}
 * gives for plain order history. Identified by phone, the same identity
 * Order's own customer directory uses: there is no registered-account module
 * yet (CLAUDE.md section 5).
 */
final readonly class LoyaltyMember
{
    public function __construct(
        public string $phone,
        /** The contact name on this phone's most recent order. */
        public string $name,
        /** Lifetime points earned (never counts down on a redemption). */
        public int $lifetimeEarnedPoints,
        /** Spendable now: lifetime earned minus whatever has been redeemed. */
        public int $balance,
        public MembershipTier $tier,
    ) {}
}
