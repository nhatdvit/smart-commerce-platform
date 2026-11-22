<?php

declare(strict_types=1);

use App\Modules\Payment\Domain\Entity\BankAccount;
use App\Modules\Payment\Domain\Entity\Payment;
use App\Modules\Payment\Domain\Enum\DiscrepancyType;
use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Modules\Payment\Domain\Enum\PaymentStatus;
use App\Modules\Payment\Domain\ValueObject\KnownBank;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Domain\ValueObject\Money;

/*
 * Payment rules, with no database and no framework.
 *
 * confirm() is where "the money arrived" becomes true, so it gets the most
 * scrutiny here: a duplicate confirmation and an unaccepted amount mismatch
 * are both CLAUDE.md section 12's named critical scenarios, and both have to
 * be refused by the domain itself, not merely by a route guard.
 */

function payment(
    PaymentMethod $method = PaymentMethod::BankTransfer,
    PaymentStatus $status = PaymentStatus::Pending,
    int $amount = 100_000,
    bool $hasDiscrepancy = false,
): Payment {
    return Payment::create(
        '01930000-0000-7000-8000-000000000001',
        '01930000-0000-7000-8000-000000000002',
        '01930000-0000-7000-8000-000000000003',
        'SCP-20261121-0001',
        $method,
        $status,
        Money::vnd($amount),
        hasDiscrepancy: $hasDiscrepancy,
    );
}

it('confirms a pending payment and names who confirmed it', function (): void {
    $confirmed = payment()->confirm(
        Money::vnd(100_000),
        'FT26112112345',
        new DateTimeImmutable('2026-11-21 08:00:00'),
        acceptDiscrepancy: false,
        actorId: 'manager-1',
    );

    expect($confirmed->status)->toBe(PaymentStatus::Paid)
        ->and($confirmed->confirmedAmount?->amount)->toBe(100_000)
        ->and($confirmed->confirmedBy)->toBe('manager-1')
        ->and($confirmed->bankTransactionReference)->toBe('FT26112112345');
});

it('refuses to confirm a payment that is already settled', function (): void {
    foreach ([PaymentStatus::Paid, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded] as $settled) {
        $existing = payment(status: $settled);

        expect(fn () => $existing->confirm(
            Money::vnd(100_000),
            null,
            new DateTimeImmutable,
            acceptDiscrepancy: true,
            actorId: 'manager-1',
        ))->toThrow(BusinessRuleViolation::class);
    }
});

it('lets a previously failed payment be confirmed', function (): void {
    // Staff marking FAILED by mistake must still be able to correct it once
    // the money is actually seen; FAILED is not a settled state.
    $confirmed = payment(status: PaymentStatus::Failed)->confirm(
        Money::vnd(100_000),
        null,
        new DateTimeImmutable,
        acceptDiscrepancy: false,
        actorId: 'manager-1',
    );

    expect($confirmed->status)->toBe(PaymentStatus::Paid);
});

it('refuses an amount mismatch unless it is explicitly accepted', function (): void {
    $existing = payment(amount: 100_000);

    expect(fn () => $existing->confirm(
        Money::vnd(80_000),
        null,
        new DateTimeImmutable,
        acceptDiscrepancy: false,
        actorId: 'manager-1',
    ))->toThrow(BusinessRuleViolation::class);

    // Accepted, it goes through - the server never auto-resolves the
    // difference, it only lets a human do so explicitly.
    $confirmed = $existing->confirm(
        Money::vnd(80_000),
        null,
        new DateTimeImmutable,
        acceptDiscrepancy: true,
        actorId: 'manager-1',
    );

    expect($confirmed->status)->toBe(PaymentStatus::Paid)
        ->and($confirmed->confirmedAmount?->amount)->toBe(80_000);
});

it('classifies a mismatch as underpaid or overpaid, and an exact match as none', function (): void {
    $existing = payment(amount: 100_000);

    expect($existing->classifyMismatch(Money::vnd(80_000)))->toBe(DiscrepancyType::Underpaid)
        ->and($existing->classifyMismatch(Money::vnd(120_000)))->toBe(DiscrepancyType::Overpaid)
        ->and($existing->classifyMismatch(Money::vnd(100_000)))->toBeNull();
});

it('refuses a negative confirmed amount', function (): void {
    expect(fn () => payment()->confirm(
        Money::vnd(-1),
        null,
        new DateTimeImmutable,
        acceptDiscrepancy: true,
        actorId: 'manager-1',
    ))->toThrow(BusinessRuleViolation::class);
});

it('flags an exception without changing the payment status', function (): void {
    $existing = payment(status: PaymentStatus::Pending);

    $discrepancy = $existing->flag(
        DiscrepancyType::UnknownReference,
        null,
        'Không khớp đơn nào',
        'cashier-1',
        'Thu ngân Demo',
        new DateTimeImmutable('2026-11-21 09:00:00'),
    );

    expect($discrepancy->type)->toBe(DiscrepancyType::UnknownReference)
        ->and($discrepancy->expectedAmount?->amount)->toBe(100_000)
        ->and($discrepancy->recordedBy)->toBe('cashier-1')
        ->and($discrepancy->recordedByName)->toBe('Thu ngân Demo');
});

it('can flag a payment that is already paid, for a duplicate transfer', function (): void {
    // DUPLICATE_TRANSFER literally implies the first transfer already
    // succeeded, so flag() must not refuse a settled payment the way
    // confirm() does.
    $paid = payment(status: PaymentStatus::Paid);

    $discrepancy = $paid->flag(
        DiscrepancyType::DuplicateTransfer,
        Money::vnd(100_000),
        null,
        'cashier-1',
        null,
        new DateTimeImmutable,
    );

    expect($discrepancy->type)->toBe(DiscrepancyType::DuplicateTransfer);
});

it('needs attention while outstanding, or once flagged, but not otherwise', function (): void {
    expect(payment(status: PaymentStatus::Pending)->needsAttention())->toBeTrue()
        ->and(payment(status: PaymentStatus::Processing)->needsAttention())->toBeTrue()
        ->and(payment(status: PaymentStatus::Paid)->needsAttention())->toBeFalse()
        // A discrepancy keeps it flagged even once no longer outstanding -
        // there is no "resolved" to fall back to (CLAUDE.md section 15).
        ->and(payment(status: PaymentStatus::Paid, hasDiscrepancy: true)->needsAttention())->toBeTrue();
});

it('refuses a payment for a negative amount', function (): void {
    expect(fn () => Payment::create(
        null,
        '01930000-0000-7000-8000-000000000002',
        '01930000-0000-7000-8000-000000000003',
        'SCP-20261121-0001',
        PaymentMethod::Cash,
        PaymentStatus::Pending,
        Money::vnd(-1),
    ))->toThrow(BusinessRuleViolation::class);
});

it('validates a bank account number and bank identifier shape', function (): void {
    expect(fn () => BankAccount::create(
        null,
        '01930000-0000-7000-8000-000000000001',
        '12345', // five digits, not six
        'Vietcombank',
        '0071000123456',
        'HO KINH DOANH',
    ))->toThrow(BusinessRuleViolation::class);

    expect(fn () => BankAccount::create(
        null,
        '01930000-0000-7000-8000-000000000001',
        '970436',
        'Vietcombank',
        '12', // too short
        'HO KINH DOANH',
    ))->toThrow(BusinessRuleViolation::class);
});

it('masks an account number to its last four digits', function (): void {
    $account = BankAccount::create(
        null,
        '01930000-0000-7000-8000-000000000001',
        '970436',
        'Vietcombank',
        '0071000123456',
        'HO KINH DOANH',
    );

    expect($account->maskedAccountNumber())->toBe('*********3456')
        ->and(BankAccount::mask('0071000123456'))->toBe('*********3456');
});

it('knows the major NAPAS participants and nothing else', function (): void {
    expect(KnownBank::isKnown('970436'))->toBeTrue()
        ->and(KnownBank::nameFor('970436'))->toBe('Vietcombank')
        ->and(KnownBank::isKnown('000000'))->toBeFalse()
        ->and(KnownBank::nameFor('000000'))->toBeNull();
});
