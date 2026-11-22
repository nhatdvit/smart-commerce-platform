<?php

declare(strict_types=1);

use App\Modules\Loyalty\Infrastructure\Persistence\Model\LoyaltySettingsModel;
use App\Shared\Infrastructure\Persistence\Model\AuditLog;
use Database\Seeders\RolePermissionSeeder;

/*
 * The store's earning policy: how many points a PAID order earns, and the
 * loyal-tier threshold.
 *
 * `storeWithStaff()` lives in AdminProductTest.php; Pest loads every file in
 * the suite, so helpers are shared rather than duplicated.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('defaults to the standard policy for a store that never configured one', function (): void {
    [, $manager] = storeWithStaff();

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/loyalty/settings')
        ->assertOk()
        ->assertJsonPath('points_per_currency', 10_000)
        ->assertJsonPath('loyal_tier_threshold_points', 100);
});

it('updates the earning policy and records it in the audit trail', function (): void {
    [$store, $manager] = storeWithStaff();

    $this->actingAs($manager, 'sanctum')
        ->putJson('/api/v1/admin/loyalty/settings', [
            'points_per_currency' => 5_000,
            'loyal_tier_threshold_points' => 200,
        ])
        ->assertOk()
        ->assertJsonPath('points_per_currency', 5_000)
        ->assertJsonPath('loyal_tier_threshold_points', 200);

    expect(LoyaltySettingsModel::query()->where('store_id', $store->id)->sole()->points_per_currency)
        ->toBe(5_000)
        ->and(AuditLog::query()->where('action', 'loyalty.settings.update')->count())->toBe(1);
});

it('refuses a non-positive points-per-currency ratio', function (): void {
    [, $manager] = storeWithStaff();

    $this->actingAs($manager, 'sanctum')
        ->putJson('/api/v1/admin/loyalty/settings', [
            'points_per_currency' => 0,
            'loyal_tier_threshold_points' => 100,
        ])
        ->assertStatus(422);
});

it('lets a cashier read the policy but not change it', function (): void {
    [, $cashier] = storeWithStaff('CASHIER');

    $this->actingAs($cashier, 'sanctum')
        ->getJson('/api/v1/admin/loyalty/settings')
        ->assertOk();

    $this->actingAs($cashier, 'sanctum')
        ->putJson('/api/v1/admin/loyalty/settings', [
            'points_per_currency' => 5_000,
            'loyal_tier_threshold_points' => 200,
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'STORE_SCOPE_DENIED');
});

it('refuses kitchen staff entirely', function (): void {
    [, $kitchen] = storeWithStaff('KITCHEN_STAFF');

    $this->actingAs($kitchen, 'sanctum')
        ->getJson('/api/v1/admin/loyalty/settings')
        ->assertForbidden();
});

it('refuses an unauthenticated caller', function (): void {
    $this->getJson('/api/v1/admin/loyalty/settings')->assertUnauthorized();
});
