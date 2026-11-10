<?php

declare(strict_types=1);

use App\Shared\Domain\ValueObject\Money;

/*
 * Money is the one type where a rounding bug becomes a financial one, so its
 * behaviour is pinned down here rather than assumed.
 */

it('treats VND as whole dong with no sub-unit', function (): void {
    expect(Money::vnd(45_000)->amount)->toBe(45_000)
        ->and((string) Money::vnd(45_000))->toBe('45000');
});

it('adds and subtracts exactly', function (): void {
    $total = Money::vnd(45_000)->plus(Money::vnd(30_000))->minus(Money::vnd(5_000));

    expect($total->amount)->toBe(70_000);
});

it('multiplies by a whole quantity', function (): void {
    expect(Money::vnd(30_000)->times(3)->amount)->toBe(90_000);
});

it('keeps precision far beyond what a float could hold', function (): void {
    // 2^53 + 1: the first integer a double cannot represent. A float-based
    // implementation would silently return the even neighbour.
    $amount = 9_007_199_254_740_993;

    expect(Money::fromString('9007199254740993')->amount)->toBe($amount)
        ->and((string) Money::vnd($amount))->toBe('9007199254740993');
});

it('serialises as a decimal string, because JSON numbers are not safe for 64-bit integers', function (): void {
    expect(json_encode(Money::vnd(45_000)))->toBe('{"amount":"45000","currency":"VND"}');
});

it('rejects a fractional amount', function (): void {
    Money::fromString('45000.50');
})->throws(InvalidArgumentException::class);

it('rejects a non-numeric amount', function (): void {
    Money::fromString('45,000');
})->throws(InvalidArgumentException::class);

it('rejects a negative quantity', function (): void {
    Money::vnd(1_000)->times(-1);
})->throws(InvalidArgumentException::class);

it('refuses to mix currencies', function (): void {
    Money::vnd(1_000)->plus(Money::fromString('1000', 'USD'));
})->throws(InvalidArgumentException::class);

it('can go negative through subtraction, which callers must check', function (): void {
    $result = Money::vnd(1_000)->minus(Money::vnd(2_500));

    expect($result->amount)->toBe(-1_500)
        ->and($result->isNegative())->toBeTrue();
});

it('compares by amount and currency, not identity', function (): void {
    expect(Money::vnd(1_000)->equals(Money::vnd(1_000)))->toBeTrue()
        ->and(Money::vnd(1_000)->equals(Money::vnd(1_001)))->toBeFalse();
});
