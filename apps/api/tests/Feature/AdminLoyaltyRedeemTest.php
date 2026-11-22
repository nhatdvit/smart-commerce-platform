<?php

declare(strict_types=1);

use App\Modules\Loyalty\Domain\Enum\EntryType;
use App\Modules\Loyalty\Infrastructure\Persistence\Model\LedgerEntryModel;
use App\Modules\Loyalty\Infrastructure\Persistence\Model\RewardModel;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use App\Shared\Infrastructure\Persistence\Model\AuditLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;

/*
 * Spending a member's points on a reward.
 *
 * There is no customer-facing redemption flow yet (CLAUDE.md delivery stage
 * 4), so this is always staff acting at the counter - `earnFor()` and
 * `storeWithStaff()` live in AdminLoyaltyMemberTest.php and
 * AdminProductTest.php; Pest loads every file in the suite, so helpers are
 * shared rather than duplicated.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('redeems a reward, debits the balance and records who did it', function (): void {
    [$store, $manager] = storeWithStaff();
    earnFor($store->id, '0909000040', 'Khách', 50);
    $reward = RewardModel::query()->create(['store_id' => $store->id, 'name' => 'Cà phê', 'points_cost' => 30]);

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/loyalty/members/0909000040/redeem', [
            'reward_id' => $reward->id,
            'note' => 'Mang đi',
        ])
        ->assertCreated()
        ->assertJsonPath('type', 'REDEEM')
        ->assertJsonPath('points', -30)
        ->assertJsonPath('reward_name', 'Cà phê')
        ->assertJsonPath('note', 'Mang đi');

    $entry = LedgerEntryModel::query()->where('phone', '0909000040')->where('type', EntryType::Redeem)->sole();
    expect($entry->actor_id)->toBe($manager->id)
        ->and(AuditLog::query()->where('action', 'loyalty.reward.redeem')->count())->toBe(1);

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/loyalty/members/0909000040')
        ->assertOk()
        ->assertJsonPath('balance', 20);
});

it('refuses to redeem more points than a member has', function (): void {
    [$store, $manager] = storeWithStaff();
    earnFor($store->id, '0909000041', 'Khách', 10);
    $reward = RewardModel::query()->create(['store_id' => $store->id, 'name' => 'Quà lớn', 'points_cost' => 100]);

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/loyalty/members/0909000041/redeem', ['reward_id' => $reward->id])
        ->assertStatus(422);

    expect(LedgerEntryModel::query()->where('phone', '0909000041')->where('type', EntryType::Redeem)->count())
        ->toBe(0);
});

it('refuses to redeem a reward that is no longer offered', function (): void {
    [$store, $manager] = storeWithStaff();
    earnFor($store->id, '0909000042', 'Khách', 50);
    $reward = RewardModel::query()->create([
        'store_id' => $store->id,
        'name' => 'Ngừng áp dụng',
        'points_cost' => 10,
        'is_active' => false,
    ]);

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/loyalty/members/0909000042/redeem', ['reward_id' => $reward->id])
        ->assertStatus(409);
});

it('reports a reward from another store as not found', function (): void {
    [$store, $manager] = storeWithStaff();
    earnFor($store->id, '0909000043', 'Khách', 50);
    $theirs = RewardModel::query()->create([
        'store_id' => Store::factory()->create()->id,
        'name' => 'Của người khác',
        'points_cost' => 10,
    ]);

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/loyalty/members/0909000043/redeem', ['reward_id' => $theirs->id])
        ->assertNotFound();
});

it('lets a cashier redeem a reward at the counter', function (): void {
    [$store, $cashier] = storeWithStaff('CASHIER');
    earnFor($store->id, '0909000044', 'Khách', 50);
    $reward = RewardModel::query()->create(['store_id' => $store->id, 'name' => 'Cà phê', 'points_cost' => 10]);

    $this->actingAs($cashier, 'sanctum')
        ->postJson('/api/v1/admin/loyalty/members/0909000044/redeem', ['reward_id' => $reward->id])
        ->assertCreated();
});

it('refuses kitchen staff entirely', function (): void {
    [$store, $kitchen] = storeWithStaff('KITCHEN_STAFF');
    earnFor($store->id, '0909000045', 'Khách', 50);
    $reward = RewardModel::query()->create(['store_id' => $store->id, 'name' => 'Cà phê', 'points_cost' => 10]);

    $this->actingAs($kitchen, 'sanctum')
        ->postJson('/api/v1/admin/loyalty/members/0909000045/redeem', ['reward_id' => $reward->id])
        ->assertForbidden();
});

it('refuses an unauthenticated caller', function (): void {
    $this->postJson('/api/v1/admin/loyalty/members/0909000046/redeem', ['reward_id' => (string) Str::uuid7()])
        ->assertUnauthorized();
});
