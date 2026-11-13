<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Audit\SensitiveDataRedactor;

/*
 * The audit trail is append-only and long-lived, so anything that leaks in is
 * effectively permanent. These tests pin the redaction rules.
 */

beforeEach(function (): void {
    $this->redactor = new SensitiveDataRedactor;
});

it('removes credentials entirely', function (string $key): void {
    $result = $this->redactor->redact([$key => 'hunter2']);

    expect($result[$key])->toBe('[REDACTED]');
})->with([
    'password',
    'new_password',
    'password_confirmation',
    'passwordHash',
    'api_key',
    'refresh_token',
    'client_secret',
    'authorization',
    'card_cvv',
    'otp_code',
]);

it('masks identifiers instead of deleting them, so reconciliation still works', function (): void {
    $result = $this->redactor->redact(['account_number' => '19001234567']);

    // Enough to tell two accounts apart during reconciliation, not enough to use.
    expect($result['account_number'])->toBe('*******4567');
});

it('redacts a masked value that is too short to mask safely', function (): void {
    // Showing 4 of 4 characters would reveal the whole thing.
    expect($this->redactor->redact(['phone' => '1234'])['phone'])->toBe('[REDACTED]');
});

it('matches keys case-insensitively and as substrings', function (): void {
    $result = $this->redactor->redact([
        'PASSWORD' => 'a',
        'userPassword' => 'b',
        'bank_account_number' => '1234567890',
    ]);

    expect($result['PASSWORD'])->toBe('[REDACTED]')
        ->and($result['userPassword'])->toBe('[REDACTED]')
        ->and($result['bank_account_number'])->toBe('******7890');
});

it('recurses into nested structures', function (): void {
    $result = $this->redactor->redact([
        'payment' => [
            'amount' => 45000,
            'bank' => ['account_number' => '19001234567', 'token' => 'secret-value'],
        ],
    ]);

    expect($result['payment']['amount'])->toBe(45000)
        ->and($result['payment']['bank']['account_number'])->toBe('*******4567')
        ->and($result['payment']['bank']['token'])->toBe('[REDACTED]');
});

it('keeps ordinary values untouched', function (): void {
    $result = $this->redactor->redact([
        'status' => 'PAID',
        'total_amount' => 135000,
        'confirmed' => true,
        'note' => null,
    ]);

    expect($result)->toBe([
        'status' => 'PAID',
        'total_amount' => 135000,
        'confirmed' => true,
        'note' => null,
    ]);
});

it('refuses to store objects, which could carry anything', function (): void {
    $result = $this->redactor->redact(['model' => new stdClass]);

    expect($result['model'])->toBe('[REDACTED]');
});

it('leaves list keys alone while still cleaning their values', function (): void {
    $result = $this->redactor->redact([
        'items' => [
            ['name' => 'Bánh mì', 'password' => 'leak'],
        ],
    ]);

    expect($result['items'][0]['name'])->toBe('Bánh mì')
        ->and($result['items'][0]['password'])->toBe('[REDACTED]');
});
