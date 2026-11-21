<?php

declare(strict_types=1);

use App\Modules\Payment\Infrastructure\Persistence\Model\BankAccount;
use App\Modules\Payment\Infrastructure\Persistence\Model\PaymentMethodSetting;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use App\Shared\Infrastructure\Persistence\Model\AuditLog;
use Database\Seeders\RolePermissionSeeder;

/*
 * Where a store's money lands, and which of the two MVP methods it currently
 * accepts - against real PostgreSQL, because the partial unique index that
 * keeps a store to one default account and the CHECK constraints behind both
 * rules are database-level invariants, not just validation (CLAUDE.md section
 * 12 names cross-store bank-account access as a critical scenario).
 *
 * `storeWithStaff()` lives in AdminProductTest.php; Pest loads every file in
 * the suite, so helpers are shared rather than duplicated.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** @param array<string, mixed> $overrides @return array<string, mixed> */
function bankAccountPayload(array $overrides = []): array
{
    return array_replace([
        'bank_bin' => '970436',
        'account_number' => '0071000123456',
        'account_name' => 'HO KINH DOANH BEP NHA MINH',
        'is_default' => true,
    ], $overrides);
}

it('reports no bank account configured yet', function (): void {
    [$store, $owner] = storeWithStaff('STORE_OWNER');

    $this->actingAs($owner, 'sanctum')
        ->getJson("/api/v1/admin/stores/{$store->id}/bank-account")
        ->assertNotFound()
        ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
});

it('sets the receiving account, masked on every read, and audits the change', function (): void {
    [$store, $owner] = storeWithStaff('STORE_OWNER');

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/v1/admin/stores/{$store->id}/bank-account", bankAccountPayload())
        ->assertOk()
        ->assertJsonPath('bank_bin', '970436')
        ->assertJsonPath('bank_name', 'Vietcombank')
        ->assertJsonPath('account_number_masked', '*********3456')
        ->assertJsonPath('is_default', true);

    $this->actingAs($owner, 'sanctum')
        ->getJson("/api/v1/admin/stores/{$store->id}/bank-account")
        ->assertOk()
        ->assertJsonPath('account_number_masked', '*********3456');

    $entry = AuditLog::query()->where('action', 'payment.bank-account.update')->sole();
    expect($entry->actor_id)->toBe($owner->id)
        ->and($entry->changes['account_number']['to'] ?? null)->toBe('*********3456')
        ->and(BankAccount::query()->where('store_id', $store->id)->sole()->account_number)->toBe('0071000123456');
});

it('replaces the previous default rather than keeping two', function (): void {
    [$store, $owner] = storeWithStaff('STORE_OWNER');
    BankAccount::factory()->inStore($store->id)->create(['bank_bin' => '970436']);

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/v1/admin/stores/{$store->id}/bank-account", bankAccountPayload([
            'bank_bin' => '970415',
            'account_number' => '0099988877766',
        ]))
        ->assertOk()
        ->assertJsonPath('bank_bin', '970415')
        ->assertJsonPath('bank_name', 'VietinBank');

    // The old row is deactivated, not deleted - a payment may have already
    // snapshotted it onto a customer's QR - so the store keeps both rows and
    // exactly one current default.
    expect(BankAccount::query()->where('store_id', $store->id)->count())->toBe(2)
        ->and(BankAccount::query()->where('store_id', $store->id)->where('is_default', true)->sole()->bank_bin)
        ->toBe('970415');
});

it('lists the known VietQR banks for the form picker, sorted by name', function (): void {
    [, $owner] = storeWithStaff('STORE_OWNER');

    $response = $this->actingAs($owner, 'sanctum')
        ->getJson('/api/v1/admin/known-banks')
        ->assertOk();

    $items = $response->json('items');
    expect($items)->not->toBeEmpty()
        ->and(collect($items)->firstWhere('bin', '970436')['name'])->toBe('Vietcombank');

    $names = collect($items)->pluck('name')->all();
    expect($names)->toBe(collect($names)->sort()->values()->all());
});

it('refuses a store manager reading the bank directory, same as the bank-account form it serves', function (): void {
    [, $manager] = storeWithStaff('STORE_MANAGER');

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/known-banks')
        ->assertForbidden()
        ->assertJsonPath('code', 'STORE_SCOPE_DENIED');
});

it('refuses a bank identifier outside the known VietQR participant list', function (): void {
    [$store, $owner] = storeWithStaff('STORE_OWNER');

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/v1/admin/stores/{$store->id}/bank-account", bankAccountPayload(['bank_bin' => '000000']))
        ->assertStatus(422)
        ->assertJsonPath('details.0.field', 'bank_bin');

    expect(BankAccount::query()->where('store_id', $store->id)->count())->toBe(0);
});

it('refuses a malformed account number', function (): void {
    [$store, $owner] = storeWithStaff('STORE_OWNER');

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/v1/admin/stores/{$store->id}/bank-account", bankAccountPayload(['account_number' => '12']))
        ->assertStatus(422)
        ->assertJsonPath('details.0.field', 'account_number');
});

it('refuses a store manager: changing where money lands is an owner-level action', function (): void {
    [$store, $manager] = storeWithStaff('STORE_MANAGER');

    $this->actingAs($manager, 'sanctum')
        ->getJson("/api/v1/admin/stores/{$store->id}/bank-account")
        ->assertForbidden()
        ->assertJsonPath('code', 'STORE_SCOPE_DENIED');

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/stores/{$store->id}/bank-account", bankAccountPayload())
        ->assertForbidden()
        ->assertJsonPath('code', 'STORE_SCOPE_DENIED');
});

it('refuses an owner of a different store entirely: cross-store bank-account access', function (): void {
    // The critical scenario CLAUDE.md section 12 names: holding the owner
    // role somewhere is not holding it for THIS store, and the explicit
    // {store} route parameter is exactly where that check could be missed.
    [, $ownerOfA] = storeWithStaff('STORE_OWNER');
    $storeB = Store::factory()->create();

    $this->actingAs($ownerOfA, 'sanctum')
        ->putJson("/api/v1/admin/stores/{$storeB->id}/bank-account", bankAccountPayload())
        ->assertForbidden()
        ->assertJsonPath('code', 'STORE_SCOPE_DENIED');

    expect(BankAccount::query()->where('store_id', $storeB->id)->count())->toBe(0);
});

it('reads the current switches, both disabled until a store sets any', function (): void {
    [$store, $owner] = storeWithStaff('STORE_OWNER');

    $response = $this->actingAs($owner, 'sanctum')
        ->getJson("/api/v1/admin/stores/{$store->id}/payment-methods")
        ->assertOk();

    $byMethod = collect($response->json('items'))->keyBy('method');
    expect($byMethod['CASH']['enabled'])->toBeFalse()
        ->and($byMethod['BANK_TRANSFER']['enabled'])->toBeFalse()
        ->and($byMethod['BANK_TRANSFER']['unavailable_reason'])->not->toBeNull();
});

it('reads back exactly what a write just set', function (): void {
    [$store, $owner] = storeWithStaff('STORE_OWNER');
    BankAccount::factory()->inStore($store->id)->create();

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/v1/admin/stores/{$store->id}/payment-methods", [
            'methods' => [['method' => 'BANK_TRANSFER', 'enabled' => true]],
        ])
        ->assertOk();

    $response = $this->actingAs($owner, 'sanctum')
        ->getJson("/api/v1/admin/stores/{$store->id}/payment-methods")
        ->assertOk();

    expect(collect($response->json('items'))->keyBy('method')['BANK_TRANSFER']['enabled'])->toBeTrue();
});

it('refuses a store manager reading the switches, same as writing them', function (): void {
    [$store, $manager] = storeWithStaff('STORE_MANAGER');

    $this->actingAs($manager, 'sanctum')
        ->getJson("/api/v1/admin/stores/{$store->id}/payment-methods")
        ->assertForbidden()
        ->assertJsonPath('code', 'STORE_SCOPE_DENIED');
});

it('enables and disables methods, defaulting the one not mentioned to its current setting', function (): void {
    [$store, $owner] = storeWithStaff('STORE_OWNER');
    BankAccount::factory()->inStore($store->id)->create();

    // A store this test created has no provisioned rows at all yet, so both
    // methods start disabled (EloquentPaymentMethodRepository::forStore());
    // enable both first to give "the one not mentioned" an actual setting to
    // default back to.
    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/v1/admin/stores/{$store->id}/payment-methods", [
            'methods' => [
                ['method' => 'CASH', 'enabled' => true],
                ['method' => 'BANK_TRANSFER', 'enabled' => true],
            ],
        ])
        ->assertOk();

    $response = $this->actingAs($owner, 'sanctum')
        ->putJson("/api/v1/admin/stores/{$store->id}/payment-methods", [
            'methods' => [['method' => 'BANK_TRANSFER', 'enabled' => false]],
        ])
        ->assertOk();

    $byMethod = collect($response->json('items'))->keyBy('method');
    expect($byMethod['BANK_TRANSFER']['enabled'])->toBeFalse()
        // CASH was never mentioned in this second request, so its setting
        // from the first request survives rather than being reset.
        ->and($byMethod['CASH']['enabled'])->toBeTrue();

    expect(PaymentMethodSetting::query()->where('store_id', $store->id)
        ->where('method', 'CASH')->sole()->enabled)->toBeTrue();
});

it('refuses to leave a store with neither method enabled', function (): void {
    [$store, $owner] = storeWithStaff('STORE_OWNER');

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/v1/admin/stores/{$store->id}/payment-methods", [
            'methods' => [
                ['method' => 'CASH', 'enabled' => false],
                ['method' => 'BANK_TRANSFER', 'enabled' => false],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonPath('details.0.field', 'items');

    // Refused before the transaction that would have provisioned any rows.
    expect(PaymentMethodSetting::query()->where('store_id', $store->id)->count())->toBe(0);
});

it('refuses to enable bank transfer with no bank account configured', function (): void {
    [$store, $owner] = storeWithStaff('STORE_OWNER');
    // No BankAccount factory call: this store genuinely has none yet.

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/v1/admin/stores/{$store->id}/payment-methods", [
            'methods' => [['method' => 'BANK_TRANSFER', 'enabled' => true]],
        ])
        ->assertStatus(422)
        ->assertJsonPath('details.0.field', 'items');
});

it('refuses a store manager, who may confirm payments but not change how a store accepts them', function (): void {
    [$store, $manager] = storeWithStaff('STORE_MANAGER');

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/stores/{$store->id}/payment-methods", [
            'methods' => [['method' => 'CASH', 'enabled' => true]],
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'STORE_SCOPE_DENIED');
});

it('refuses an unauthenticated caller', function (): void {
    $store = Store::factory()->create();

    $this->getJson("/api/v1/admin/stores/{$store->id}/bank-account")->assertUnauthorized();
    $this->getJson('/api/v1/admin/known-banks')->assertUnauthorized();
    $this->getJson("/api/v1/admin/stores/{$store->id}/payment-methods")->assertUnauthorized();
    $this->putJson("/api/v1/admin/stores/{$store->id}/payment-methods", ['methods' => []])
        ->assertUnauthorized();
});
