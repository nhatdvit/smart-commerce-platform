<?php

declare(strict_types=1);

use App\Modules\Payment\Domain\ValueObject\TransferReference;
use App\Shared\Domain\Exception\BusinessRuleViolation;

it('derives a reference from an order code without repeating the prefix', function (): void {
    // A regression test: the order code already carries this same prefix in
    // every deployment this platform has, and a first version of this method
    // concatenated it again, producing "SCPSCP20261121..." instead of
    // "SCP20261121...".
    $reference = TransferReference::fromOrderCode('SCP', 'SCP-20261121-0042');

    expect($reference->value)->toBe('SCP202611210042');
});

it('leaves a prefix alone when it does not actually appear in the order code', function (): void {
    $reference = TransferReference::fromOrderCode('PQ', 'SCP-20261121-0042');

    expect($reference->value)->toBe('PQSCP202611210042');
});

it('appends the disambiguator on a retry, and only then', function (): void {
    $first = TransferReference::fromOrderCode('SCP', 'SCP-20261121-0042');
    $retry = TransferReference::fromOrderCode('SCP', 'SCP-20261121-0042', 'X1');

    expect($first->value)->not->toBe($retry->value)
        ->and($retry->value)->toEndWith('X1');
});

it('never exceeds the column length, keeping the prefix and disambiguator whole', function (): void {
    $reference = TransferReference::fromOrderCode(
        'SCP',
        'SCP-20261121-0000000000000000000000000001',
        'RETRY1',
    );

    expect(strlen($reference->value))->toBeLessThanOrEqual(TransferReference::MAX_LENGTH)
        ->and($reference->value)->toStartWith('SCP')
        ->and($reference->value)->toEndWith('RETRY1');
});

it('refuses anything outside 6 to 32 characters of A-Z and 0-9', function (): void {
    expect(fn () => TransferReference::create('AB'))->toThrow(BusinessRuleViolation::class);
    expect(fn () => TransferReference::create('SCP 123'))->toThrow(BusinessRuleViolation::class);
    expect(fn () => TransferReference::create(str_repeat('A', 33)))->toThrow(BusinessRuleViolation::class);
});

it('upper-cases a lower-case reference rather than rejecting it', function (): void {
    expect(TransferReference::create('scp12345')->value)->toBe('SCP12345');
});
