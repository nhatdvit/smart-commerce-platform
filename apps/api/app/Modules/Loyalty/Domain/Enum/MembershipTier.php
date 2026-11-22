<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Enum;

/**
 * The one tier rule this milestone has: LIFETIME points earned (never
 * current balance) at or above the store's configured threshold is "Thân
 * thiết", everyone else is "Thành viên". Lifetime earned, not balance, on
 * purpose - a member who redeems points for a reward has not become less
 * loyal, and a tier that fell every time someone used the program would
 * punish the exact behaviour it exists to reward. Nothing here expires or
 * demotes a member either; that is a policy decision CLAUDE.md section 15
 * leaves open, same as promotions' resolution questions.
 */
enum MembershipTier: string
{
    case Member = 'MEMBER';
    case Loyal = 'LOYAL';

    public static function forLifetimeEarnedPoints(int $lifetimeEarnedPoints, int $loyalThreshold): self
    {
        return $lifetimeEarnedPoints >= $loyalThreshold ? self::Loyal : self::Member;
    }

    public function label(): string
    {
        return match ($this) {
            self::Member => 'Thành viên',
            self::Loyal => 'Thân thiết',
        };
    }
}
