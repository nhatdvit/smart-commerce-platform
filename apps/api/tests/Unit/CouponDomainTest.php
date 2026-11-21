<?php

declare(strict_types=1);

use App\Modules\Pricing\Domain\Entity\Coupon;
use App\Modules\Pricing\Domain\Enum\CouponAvailability;
use App\Modules\Pricing\Domain\Enum\CouponStatus;
use App\Modules\Pricing\Domain\Enum\DiscountType;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Domain\ValueObject\Money;

/*
 * Coupon rules, with no database and no framework.
 *
 * Availability especially: it is derived from a clock, so it is worth asking
 * the question at several instants rather than trusting whatever "now" the
 * test machine happens to have (CLAUDE.md sections 3 and 12).
 */

function coupon(
    DiscountType $type = DiscountType::FixedAmount,
    int $value = 5_000,
    ?int $maxDiscount = null,
    int $minOrder = 50_000,
    ?int $usageLimit = null,
    int $redeemed = 0,
    ?DateTimeImmutable $startsAt = null,
    ?DateTimeImmutable $endsAt = null,
    CouponStatus $status = CouponStatus::Active,
    string $code = 'GIAM5K',
): Coupon {
    return Coupon::create(
        null,
        '01930000-0000-7000-8000-000000000001',
        $code,
        null,
        $type,
        $value,
        $maxDiscount === null ? null : Money::vnd($maxDiscount),
        Money::vnd($minOrder),
        $usageLimit,
        $redeemed,
        $startsAt,
        $endsAt,
        $status,
    );
}

it('stores a code upper case and trimmed', function (): void {
    expect(coupon(code: '  sang10 ')->code)->toBe('SANG10');
});

it('refuses a code nobody could read out over a counter', function (): void {
    foreach (['AB', 'giảm10', 'SANG 10', 'SANG@10', str_repeat('A', 33)] as $code) {
        expect(fn (): Coupon => coupon(code: $code))->toThrow(BusinessRuleViolation::class);
    }
});

it('refuses a percentage outside one to a hundred', function (): void {
    expect(fn (): Coupon => coupon(DiscountType::Percentage, 0, minOrder: 0))
        ->toThrow(BusinessRuleViolation::class);

    expect(fn (): Coupon => coupon(DiscountType::Percentage, 101, minOrder: 0))
        ->toThrow(BusinessRuleViolation::class);

    expect(coupon(DiscountType::Percentage, 100, minOrder: 0)->discountValue)->toBe(100);
});

it('refuses a fixed discount bigger than the order it requires', function (): void {
    // A shop does not pay its customers to order breakfast.
    expect(fn (): Coupon => coupon(DiscountType::FixedAmount, 60_000, minOrder: 50_000))
        ->toThrow(BusinessRuleViolation::class);

    // With no minimum there is nothing to exceed; the basket caps it instead.
    expect(coupon(DiscountType::FixedAmount, 60_000, minOrder: 0)->discountValue)->toBe(60_000);
});

it('allows a ceiling only on a percentage', function (): void {
    expect(coupon(DiscountType::Percentage, 10, maxDiscount: 20_000, minOrder: 0)->maxDiscount?->amount)
        ->toBe(20_000);

    expect(fn (): Coupon => coupon(DiscountType::FixedAmount, 5_000, maxDiscount: 20_000))
        ->toThrow(BusinessRuleViolation::class);
});

it('refuses a usage limit below what has already been redeemed', function (): void {
    expect(fn (): Coupon => coupon(usageLimit: 10, redeemed: 18))
        ->toThrow(BusinessRuleViolation::class);
});

it('counts what is left, and says nothing when there is no limit', function (): void {
    expect(coupon(usageLimit: 100, redeemed: 18)->remainingUses())->toBe(82)
        ->and(coupon()->remainingUses())->toBeNull();
});

it('reads a running code as running', function (): void {
    $now = new DateTimeImmutable('2026-11-20 08:00:00');

    expect(coupon()->availabilityAt($now))->toBe(CouponAvailability::Running);
});

it('tells apart the four ways a code can be unusable', function (): void {
    $now = new DateTimeImmutable('2026-11-20 08:00:00');
    $yesterday = new DateTimeImmutable('2026-11-19 08:00:00');
    $tomorrow = new DateTimeImmutable('2026-11-21 08:00:00');

    expect(coupon(startsAt: $tomorrow)->availabilityAt($now))
        ->toBe(CouponAvailability::Scheduled)
        ->and(coupon(startsAt: $yesterday, endsAt: $now)->availabilityAt($now))
        // The window closes AT ends_at, not after it.
        ->toBe(CouponAvailability::Expired)
        ->and(coupon(usageLimit: 20, redeemed: 20)->availabilityAt($now))
        ->toBe(CouponAvailability::Exhausted)
        ->and(coupon(status: CouponStatus::Paused)->availabilityAt($now))
        ->toBe(CouponAvailability::Paused);
});

it('puts what the shop decided before what the calendar says', function (): void {
    $now = new DateTimeImmutable('2026-11-20 08:00:00');

    // An archived code that is also expired reads as archived: the operator's
    // decision is the useful answer, not the date.
    $archived = coupon(
        endsAt: new DateTimeImmutable('2026-11-19 08:00:00'),
        status: CouponStatus::Archived,
    );

    expect($archived->availabilityAt($now))->toBe(CouponAvailability::Archived);
});

it('keeps the redemption count across an edit', function (): void {
    $edited = coupon(usageLimit: 100, redeemed: 18)->withEdits(
        'Giảm sáng',
        DiscountType::Percentage,
        10,
        Money::vnd(20_000),
        Money::vnd(100_000),
        100,
        null,
        null,
        CouponStatus::Paused,
    );

    // How many times a code has been used is the system's record, not a field
    // an edit can reset.
    expect($edited->redeemedCount)->toBe(18)
        ->and($edited->code)->toBe('GIAM5K')
        ->and($edited->discountType)->toBe(DiscountType::Percentage)
        ->and($edited->status)->toBe(CouponStatus::Paused);
});

it('refuses a window that ends before it starts', function (): void {
    expect(fn (): Coupon => coupon(
        startsAt: new DateTimeImmutable('2026-11-21 08:00:00'),
        endsAt: new DateTimeImmutable('2026-11-20 08:00:00'),
    ))->toThrow(BusinessRuleViolation::class);
});
