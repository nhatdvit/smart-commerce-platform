<?php

declare(strict_types=1);

use App\Modules\Loyalty\Domain\Entity\Reward;
use App\Modules\Loyalty\Domain\Enum\MembershipTier;
use App\Modules\Loyalty\Domain\ValueObject\LoyaltySettings;
use App\Shared\Domain\Exception\BusinessRuleViolation;

/*
 * Loyalty rules, with no database and no framework.
 */

it('tiers by lifetime earned points, never by current balance', function (): void {
    expect(MembershipTier::forLifetimeEarnedPoints(0, 100))->toBe(MembershipTier::Member)
        ->and(MembershipTier::forLifetimeEarnedPoints(99, 100))->toBe(MembershipTier::Member)
        ->and(MembershipTier::forLifetimeEarnedPoints(100, 100))->toBe(MembershipTier::Loyal)
        ->and(MembershipTier::forLifetimeEarnedPoints(500, 100))->toBe(MembershipTier::Loyal);
});

it('labels tiers in Vietnamese', function (): void {
    expect(MembershipTier::Member->label())->toBe('Thành viên')
        ->and(MembershipTier::Loyal->label())->toBe('Thân thiết');
});

it('refuses a reward with no name', function (): void {
    expect(fn (): Reward => Reward::create(null, 'store-1', '  ', 10, null))
        ->toThrow(BusinessRuleViolation::class);
});

it('refuses a reward that costs nothing', function (): void {
    expect(fn (): Reward => Reward::create(null, 'store-1', 'Cà phê miễn phí', 0, null))
        ->toThrow(BusinessRuleViolation::class);
});

it('trims a reward name', function (): void {
    expect(Reward::create(null, 'store-1', '  Cà phê miễn phí  ', 10, null)->name)
        ->toBe('Cà phê miễn phí');
});

it('refuses a non-positive points-per-currency ratio', function (): void {
    expect(fn (): LoyaltySettings => LoyaltySettings::create('store-1', 0, 100))
        ->toThrow(BusinessRuleViolation::class);
});

it('refuses a negative loyal-tier threshold', function (): void {
    expect(fn (): LoyaltySettings => LoyaltySettings::create('store-1', 10_000, -1))
        ->toThrow(BusinessRuleViolation::class);
});

it('floors points earned, never granting a part point', function (): void {
    $settings = LoyaltySettings::create('store-1', 10_000, 100);

    expect($settings->pointsFor(25_000))->toBe(2)
        ->and($settings->pointsFor(9_999))->toBe(0)
        ->and($settings->pointsFor(0))->toBe(0)
        ->and($settings->pointsFor(-5_000))->toBe(0);
});

it('has a sensible default policy for a store that never configured one', function (): void {
    $settings = LoyaltySettings::default('store-1');

    expect($settings->pointsPerCurrency)->toBe(LoyaltySettings::DEFAULT_POINTS_PER_CURRENCY)
        ->and($settings->loyalTierThresholdPoints)->toBe(LoyaltySettings::DEFAULT_LOYAL_TIER_THRESHOLD_POINTS);
});
