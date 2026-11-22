<?php

declare(strict_types=1);

use App\Modules\Loyalty\Infrastructure\Persistence\Model\RewardModel;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use App\Shared\Infrastructure\Persistence\Model\AuditLog;
use Database\Seeders\RolePermissionSeeder;

/*
 * Admin reward endpoints: the redeemable-reward catalogue.
 *
 * `storeWithStaff()` lives in AdminProductTest.php; Pest loads every file in
 * the suite, so helpers are shared rather than duplicated.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('creates a reward and records it in the audit trail', function (): void {
    [$store, $manager] = storeWithStaff();

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/loyalty/rewards', [
            'name' => 'Cà phê miễn phí',
            'points_cost' => 50,
            'icon' => 'drink',
        ])
        ->assertCreated()
        ->assertJsonPath('name', 'Cà phê miễn phí')
        ->assertJsonPath('points_cost', 50)
        ->assertJsonPath('icon', 'drink')
        ->assertJsonPath('is_active', true)
        ->assertJsonPath('redeemed_count', 0);

    expect(RewardModel::query()->where('store_id', $store->id)->sole()->name)->toBe('Cà phê miễn phí')
        ->and(AuditLog::query()->where('action', 'loyalty.reward.create')->count())->toBe(1);
});

it('refuses a reward with no name or a non-positive cost', function (): void {
    [, $manager] = storeWithStaff();

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/loyalty/rewards', ['name' => '', 'points_cost' => 50])
        ->assertStatus(422);

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/loyalty/rewards', ['name' => 'Quà', 'points_cost' => 0])
        ->assertStatus(422);
});

it('lists this store rewards only, newest first', function (): void {
    [$store, $manager] = storeWithStaff();
    $first = RewardModel::query()->create(['store_id' => $store->id, 'name' => 'A', 'points_cost' => 10]);
    $first->forceFill(['created_at' => now()->subMinute()])->save();
    $second = RewardModel::query()->create(['store_id' => $store->id, 'name' => 'B', 'points_cost' => 20]);
    RewardModel::query()->create(['store_id' => Store::factory()->create()->id, 'name' => 'C', 'points_cost' => 30]);

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/loyalty/rewards')
        ->assertOk()
        ->assertJsonCount(2, 'items')
        ->assertJsonPath('items.0.id', $second->id)
        ->assertJsonPath('items.1.id', $first->id);
});

it('edits a reward and can retire it', function (): void {
    [$store, $manager] = storeWithStaff();
    $reward = RewardModel::query()->create([
        'store_id' => $store->id,
        'name' => 'Xôi gà',
        'points_cost' => 80,
        'is_active' => true,
    ]);

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/loyalty/rewards/{$reward->id}", [
            'name' => 'Xôi gà đặc biệt',
            'points_cost' => 100,
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('name', 'Xôi gà đặc biệt')
        ->assertJsonPath('points_cost', 100)
        ->assertJsonPath('is_active', false);

    expect($reward->refresh()->is_active)->toBeFalse();
});

it('reports another store reward as not found', function (): void {
    [, $manager] = storeWithStaff();
    $theirs = RewardModel::query()->create([
        'store_id' => Store::factory()->create()->id,
        'name' => 'Của người khác',
        'points_cost' => 10,
    ]);

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/loyalty/rewards/{$theirs->id}", ['name' => 'X', 'points_cost' => 10])
        ->assertNotFound()
        ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
});

it('lets a cashier read rewards but not create or edit them', function (): void {
    [$store, $cashier] = storeWithStaff('CASHIER');
    $reward = RewardModel::query()->create(['store_id' => $store->id, 'name' => 'A', 'points_cost' => 10]);

    $this->actingAs($cashier, 'sanctum')
        ->getJson('/api/v1/admin/loyalty/rewards')
        ->assertOk();

    $this->actingAs($cashier, 'sanctum')
        ->postJson('/api/v1/admin/loyalty/rewards', ['name' => 'B', 'points_cost' => 10])
        ->assertForbidden()
        ->assertJsonPath('code', 'STORE_SCOPE_DENIED');

    $this->actingAs($cashier, 'sanctum')
        ->putJson("/api/v1/admin/loyalty/rewards/{$reward->id}", ['name' => 'C', 'points_cost' => 10])
        ->assertForbidden();
});

it('refuses kitchen staff entirely', function (): void {
    [$store, $kitchen] = storeWithStaff('KITCHEN_STAFF');
    RewardModel::query()->create(['store_id' => $store->id, 'name' => 'A', 'points_cost' => 10]);

    $this->actingAs($kitchen, 'sanctum')
        ->getJson('/api/v1/admin/loyalty/rewards')
        ->assertForbidden();
});

it('refuses an unauthenticated caller', function (): void {
    $this->getJson('/api/v1/admin/loyalty/rewards')->assertUnauthorized();
});
