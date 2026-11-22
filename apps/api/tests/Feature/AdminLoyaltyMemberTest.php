<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Enum\MembershipStatus;
use App\Modules\Identity\Infrastructure\Persistence\Model\Role;
use App\Modules\Identity\Infrastructure\Persistence\Model\StoreMember;
use App\Modules\Identity\Infrastructure\Persistence\Model\User;
use App\Modules\Loyalty\Domain\Enum\EntryType;
use App\Modules\Loyalty\Infrastructure\Persistence\Model\LedgerEntryModel;
use App\Modules\Loyalty\Infrastructure\Persistence\Model\RewardModel;
use App\Modules\Order\Domain\Enum\OrderStatus;
use App\Modules\Order\Infrastructure\Persistence\Model\Order;
use App\Modules\Payment\Infrastructure\Persistence\Model\Payment;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use Database\Seeders\RolePermissionSeeder;

/*
 * The member directory, against real PostgreSQL.
 *
 * There is no registered-account module yet (CLAUDE.md section 5), so a
 * member is a phone number, read as a GROUP BY over `loyalty_ledger_entries`
 * with a read-through join into `orders` for the name to show - the same
 * shape AdminCustomerTest already proves for order history, applied here to
 * the points ledger instead.
 *
 * `storeWithStaff()` lives in AdminProductTest.php; Pest loads every file in
 * the suite, so helpers are shared rather than duplicated.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** A PAID order and payment for a phone, with an EARN ledger row for it. */
function earnFor(
    string $storeId,
    string $phone,
    string $name,
    int $points,
    ?DateTimeInterface $createdAt = null,
): LedgerEntryModel {
    /** @var Order $order */
    $order = Order::factory()
        ->inStore($storeId)
        ->status(OrderStatus::Completed)
        ->totalling($points * 10_000)
        ->create(['contact_phone' => $phone, 'contact_name' => $name]);

    $payment = Payment::factory()->on($order->id, $storeId, $points * 10_000)->paid()->create();

    $entry = LedgerEntryModel::query()->create([
        'store_id' => $storeId,
        'phone' => $phone,
        'type' => EntryType::Earn,
        'points' => $points,
        'payment_id' => $payment->id,
    ]);

    if ($createdAt !== null) {
        $entry->forceFill(['created_at' => $createdAt])->save();
    }

    return $entry;
}

it('sums a phone lifetime earned points and current balance across rows', function (): void {
    [$store, $manager] = storeWithStaff();
    earnFor($store->id, '0909000001', 'Nguyễn Minh Anh', 40);
    earnFor($store->id, '0909000001', 'Nguyễn Minh Anh', 30);

    $reward = RewardModel::query()->create(['store_id' => $store->id, 'name' => 'Quà', 'points_cost' => 30]);
    LedgerEntryModel::query()->create([
        'store_id' => $store->id,
        'phone' => '0909000001',
        'type' => EntryType::Redeem,
        'points' => -30,
        'reward_id' => $reward->id,
        'actor_id' => $manager->id,
    ]);

    $response = $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/loyalty/members')
        ->assertOk()
        ->assertJsonCount(1, 'items');

    expect($response->json('items.0.phone'))->toBe('0909000001')
        ->and($response->json('items.0.lifetime_earned_points'))->toBe(70)
        // Spending points does not undo having earned them.
        ->and($response->json('items.0.balance'))->toBe(40)
        // Below the default 100-point loyal-tier threshold.
        ->and($response->json('items.0.tier'))->toBe('MEMBER');
});

it('promotes a member to the loyal tier by lifetime earned, not by balance', function (): void {
    [$store, $manager] = storeWithStaff();
    earnFor($store->id, '0909000002', 'Khách thân thiết', 150);
    $reward = RewardModel::query()->create(['store_id' => $store->id, 'name' => 'Quà', 'points_cost' => 140]);
    LedgerEntryModel::query()->create([
        'store_id' => $store->id,
        'phone' => '0909000002',
        'type' => EntryType::Redeem,
        'points' => -140,
        'reward_id' => $reward->id,
        'actor_id' => $manager->id,
    ]);

    // Lifetime earned (150) clears the default 100-point threshold even
    // though almost everything has since been redeemed.
    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/loyalty/members')
        ->assertOk()
        ->assertJsonPath('items.0.tier', 'LOYAL')
        ->assertJsonPath('items.0.balance', 10);
});

it('shows the most recent contact name for a phone that ordered under two names', function (): void {
    [$store, $manager] = storeWithStaff();
    earnFor($store->id, '0909000003', 'Khách lẻ', 10, createdAt: now()->subDay());
    earnFor($store->id, '0909000003', 'Trần Quốc Huy', 10, createdAt: now());

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/loyalty/members')
        ->assertOk()
        ->assertJsonPath('items.0.name', 'Trần Quốc Huy');
});

it('lists this store members only', function (): void {
    [$store, $manager] = storeWithStaff();
    earnFor($store->id, '0909000004', 'Khách A', 10);
    earnFor(Store::factory()->create()->id, '0909000005', 'Khách cửa hàng khác', 999);

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/loyalty/members')
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.phone', '0909000004');
});

it('searches by phone or by contact name', function (): void {
    [$store, $manager] = storeWithStaff();
    earnFor($store->id, '0909111222', 'Lê Thảo Vy', 20);
    earnFor($store->id, '0909333444', 'Phạm Hoàng Long', 20);

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/loyalty/members?'.http_build_query(['q' => '111222']))
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.phone', '0909111222');

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/loyalty/members?'.http_build_query(['q' => 'thảo']))
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.phone', '0909111222');
});

it('pages through members with a cursor, ordered by lifetime earned', function (): void {
    [$store, $manager] = storeWithStaff();
    earnFor($store->id, '0909000010', 'Khách 10', 10);
    earnFor($store->id, '0909000011', 'Khách 11', 30);
    earnFor($store->id, '0909000012', 'Khách 12', 20);

    $first = $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/loyalty/members?limit=2')
        ->assertOk()
        ->assertJsonCount(2, 'items')
        ->assertJsonPath('has_more', true);

    expect($first->json('items.0.phone'))->toBe('0909000011')
        ->and($first->json('items.1.phone'))->toBe('0909000012');

    $cursor = $first->json('next_cursor');
    expect($cursor)->not->toBeNull();

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/loyalty/members?'.http_build_query(['limit' => 2, 'cursor' => $cursor]))
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.phone', '0909000010')
        ->assertJsonPath('has_more', false);
});

it('shows a member full history, most recent first', function (): void {
    [$store, $manager] = storeWithStaff();
    earnFor($store->id, '0909000020', 'Khách', 50, createdAt: now()->subHour());
    $reward = RewardModel::query()->create(['store_id' => $store->id, 'name' => 'Cà phê', 'points_cost' => 20]);
    LedgerEntryModel::query()->create([
        'store_id' => $store->id,
        'phone' => '0909000020',
        'type' => EntryType::Redeem,
        'points' => -20,
        'reward_id' => $reward->id,
        'actor_id' => $manager->id,
        'note' => 'Khách yêu cầu mang đi',
    ]);

    $response = $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/loyalty/members/0909000020')
        ->assertOk()
        ->assertJsonPath('phone', '0909000020')
        ->assertJsonPath('lifetime_earned_points', 50)
        ->assertJsonPath('balance', 30)
        ->assertJsonCount(2, 'history');

    expect($response->json('history.0.type'))->toBe('REDEEM')
        ->and($response->json('history.0.points'))->toBe(-20)
        ->and($response->json('history.0.reward_name'))->toBe('Cà phê')
        ->and($response->json('history.0.note'))->toBe('Khách yêu cầu mang đi')
        ->and($response->json('history.0.actor_name'))->not->toBeNull()
        ->and($response->json('history.1.type'))->toBe('EARN');
});

it('refuses a manager of a different store entirely: cross-store access', function (): void {
    [$storeA] = storeWithStaff('STORE_MANAGER');
    earnFor($storeA->id, '0909000030', 'Khách', 10);

    $storeB = Store::factory()->create();
    $managerOfB = User::factory()->create();
    StoreMember::query()->create([
        'store_id' => $storeB->id,
        'user_id' => $managerOfB->id,
        'role_id' => Role::query()->where('code', 'STORE_MANAGER')->sole()->id,
        'status' => MembershipStatus::Active->value,
    ]);

    $this->actingAs($managerOfB, 'sanctum')
        ->getJson('/api/v1/admin/loyalty/members')
        ->assertForbidden()
        ->assertJsonPath('code', 'STORE_SCOPE_DENIED');
});

it('refuses an unauthenticated caller', function (): void {
    $this->getJson('/api/v1/admin/loyalty/members')->assertUnauthorized();
});
