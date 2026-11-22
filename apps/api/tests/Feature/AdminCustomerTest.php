<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Enum\MembershipStatus;
use App\Modules\Identity\Infrastructure\Persistence\Model\Role;
use App\Modules\Identity\Infrastructure\Persistence\Model\StoreMember;
use App\Modules\Identity\Infrastructure\Persistence\Model\User;
use App\Modules\Order\Domain\Enum\OrderStatus;
use App\Modules\Order\Infrastructure\Persistence\Model\Order;
use App\Modules\Payment\Infrastructure\Persistence\Model\Payment;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use Database\Seeders\RolePermissionSeeder;

/*
 * The guest-customer directory, against real PostgreSQL.
 *
 * There is no registered-account module yet (CLAUDE.md section 5), so this is
 * entirely a GROUP BY over `orders.contact_phone` with a read-through join
 * into `payments` for what was actually collected - a real aggregate query,
 * not something SQLite's looser typing could stand in for.
 *
 * `storeWithStaff()` lives in AdminProductTest.php; Pest loads every file in
 * the suite, so helpers are shared rather than duplicated.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** One order for a named guest, with its payment settled or not. */
function orderForCustomer(
    string $storeId,
    string $phone,
    string $name,
    int $total,
    bool $paid,
    ?DateTimeInterface $createdAt = null,
): Order {
    /** @var Order $order */
    $order = Order::factory()
        ->inStore($storeId)
        ->status(OrderStatus::Completed)
        ->totalling($total)
        ->create([
            'contact_phone' => $phone,
            'contact_name' => $name,
        ]);

    // `created_at` is not mass-assignable (Eloquent manages it), and these
    // tests need precise control over it to prove "most recent" and the
    // cursor ordering - set directly after creation instead.
    if ($createdAt !== null) {
        $order->forceFill(['created_at' => $createdAt])->save();
    }

    $payment = Payment::factory()->on($order->id, $storeId, $total);
    ($paid ? $payment->paid() : $payment)->create();

    return $order;
}

it('groups a guest customer orders by phone, counting only paid orders as spent', function (): void {
    [$store, $manager] = storeWithStaff();
    orderForCustomer($store->id, '0909000001', 'Nguyễn Minh Anh', 50_000, paid: true);
    orderForCustomer($store->id, '0909000001', 'Nguyễn Minh Anh', 30_000, paid: false);

    $response = $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/customers')
        ->assertOk()
        ->assertJsonCount(1, 'items');

    expect($response->json('items.0.phone'))->toBe('0909000001')
        ->and($response->json('items.0.name'))->toBe('Nguyễn Minh Anh')
        ->and($response->json('items.0.order_count'))->toBe(2)
        // Only the paid order counts as money actually spent - a pending cash
        // order was never collected (CLAUDE.md section 6).
        ->and($response->json('items.0.total_spent.amount'))->toBe('50000');
});

it('shows the most recent contact name for a phone that ordered under two names', function (): void {
    [$store, $manager] = storeWithStaff();
    orderForCustomer($store->id, '0909000002', 'Khách lẻ', 20_000, paid: true, createdAt: now()->subDay());
    orderForCustomer($store->id, '0909000002', 'Trần Quốc Huy', 20_000, paid: true, createdAt: now());

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/customers')
        ->assertOk()
        ->assertJsonPath('items.0.name', 'Trần Quốc Huy');
});

it('lists this store customers only, newest activity first', function (): void {
    [$store, $manager] = storeWithStaff();
    orderForCustomer($store->id, '0909000003', 'Khách A', 10_000, paid: true, createdAt: now()->subHour());
    orderForCustomer($store->id, '0909000004', 'Khách B', 10_000, paid: true, createdAt: now());
    orderForCustomer(Store::factory()->create()->id, '0909000005', 'Khách cửa hàng khác', 999_000, paid: true);

    $response = $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/customers')
        ->assertOk()
        ->assertJsonCount(2, 'items');

    expect($response->json('items.0.phone'))->toBe('0909000004')
        ->and($response->json('items.1.phone'))->toBe('0909000003');
});

it('searches by phone or by contact name', function (): void {
    [$store, $manager] = storeWithStaff();
    orderForCustomer($store->id, '0909111222', 'Lê Thảo Vy', 60_000, paid: true);
    orderForCustomer($store->id, '0909333444', 'Phạm Hoàng Long', 40_000, paid: true);

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/customers?'.http_build_query(['q' => '111222']))
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.phone', '0909111222');

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/customers?'.http_build_query(['q' => 'thảo']))
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.phone', '0909111222');
});

it('pages through customers with a cursor, oldest-activity customers on later pages', function (): void {
    [$store, $manager] = storeWithStaff();
    orderForCustomer($store->id, '0909000010', 'Khách 10', 10_000, paid: true, createdAt: now()->subMinutes(3));
    orderForCustomer($store->id, '0909000011', 'Khách 11', 10_000, paid: true, createdAt: now()->subMinutes(2));
    orderForCustomer($store->id, '0909000012', 'Khách 12', 10_000, paid: true, createdAt: now()->subMinute());

    $first = $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/customers?limit=2')
        ->assertOk()
        ->assertJsonCount(2, 'items')
        ->assertJsonPath('has_more', true);

    $cursor = $first->json('next_cursor');
    expect($cursor)->not->toBeNull();

    $second = $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/customers?'.http_build_query(['limit' => 2, 'cursor' => $cursor]))
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('has_more', false);

    expect($second->json('items.0.phone'))->toBe('0909000010');
});

it('refuses a manager of a different store entirely: cross-store access', function (): void {
    [$storeA] = storeWithStaff('STORE_MANAGER');
    orderForCustomer($storeA->id, '0909000020', 'Khách', 10_000, paid: true);

    $storeB = Store::factory()->create();
    $managerOfB = User::factory()->create();
    StoreMember::query()->create([
        'store_id' => $storeB->id,
        'user_id' => $managerOfB->id,
        'role_id' => Role::query()->where('code', 'STORE_MANAGER')->sole()->id,
        'status' => MembershipStatus::Active->value,
    ]);

    // Same permission, held in a different store - the request still
    // resolves to storeA (the configured default), where this user has no
    // membership at all.
    $this->actingAs($managerOfB, 'sanctum')
        ->getJson('/api/v1/admin/customers')
        ->assertForbidden()
        ->assertJsonPath('code', 'STORE_SCOPE_DENIED');
});

it('refuses an unauthenticated caller', function (): void {
    $this->getJson('/api/v1/admin/customers')->assertUnauthorized();
});
