<?php

declare(strict_types=1);

use App\Modules\Menu\Infrastructure\Persistence\Model\Menu;
use App\Modules\Menu\Infrastructure\Persistence\Model\MenuItem;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use App\Shared\Infrastructure\Persistence\Model\AuditLog;
use Database\Seeders\RolePermissionSeeder;

/*
 * The morning prep sheet: how many portions of each dish were made today.
 *
 * `storeWithStaff()` and `listableVariant()` live in AdminProductTest.php and
 * AdminMenuTest.php; Pest loads every file in the suite, so helpers are shared
 * rather than duplicated.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** A dish on this store's menu, with today's numbers already on it. */
function dishOnMenu(
    string $storeId,
    string $menuId,
    string $name = 'Xôi gà',
    int $available = 5,
    int $capacity = 30,
): MenuItem {
    [, $variant] = listableVariant($storeId, $name);

    return MenuItem::factory()->on($menuId, $variant->id, $storeId)->create([
        'available_quantity' => $available,
        'daily_capacity' => $capacity,
    ]);
}

it('records the whole morning in one request', function (): void {
    [$store, $manager] = storeWithStaff();
    $menu = Menu::factory()->inStore($store->id)->create();
    $banhMi = dishOnMenu($store->id, $menu->id, 'Bánh mì pate trứng');
    $xoi = dishOnMenu($store->id, $menu->id, 'Xôi gà xé');

    $this->actingAs($manager, 'sanctum')
        ->putJson('/api/v1/admin/menu-items/daily-portions', [
            'items' => [
                ['id' => $banhMi->id, 'prepared_quantity' => 40],
                ['id' => $xoi->id, 'prepared_quantity' => 30],
            ],
        ])
        ->assertOk()
        ->assertJsonCount(2, 'items')
        ->assertJsonPath('items.0.available_quantity', 40)
        ->assertJsonPath('items.0.daily_capacity', 40)
        ->assertJsonPath('items.1.available_quantity', 30);

    // Both numbers move together: at the start of service nothing is sold yet,
    // so what was prepared and what is left are the same thing.
    expect($banhMi->refresh()->available_quantity)->toBe(40)
        ->and($banhMi->daily_capacity)->toBe(40)
        ->and($banhMi->is_available)->toBeTrue()
        ->and($xoi->refresh()->daily_capacity)->toBe(30);
});

it('marks a dish nobody made as sold out', function (): void {
    [$store, $manager] = storeWithStaff();
    $menu = Menu::factory()->inStore($store->id)->create();
    $dish = dishOnMenu($store->id, $menu->id);

    $this->actingAs($manager, 'sanctum')
        ->putJson('/api/v1/admin/menu-items/daily-portions', [
            'items' => [['id' => $dish->id, 'prepared_quantity' => 0]],
        ])
        ->assertOk()
        ->assertJsonPath('items.0.is_available', false)
        ->assertJsonPath('items.0.available_quantity', 0);

    expect($dish->refresh()->is_available)->toBeFalse();
});

it('applies the sheet as one transaction or not at all', function (): void {
    [$store, $manager] = storeWithStaff();
    $menu = Menu::factory()->inStore($store->id)->create();
    $ours = dishOnMenu($store->id, $menu->id, 'Bánh mì pate trứng');

    $otherStore = Store::factory()->create();
    $theirMenu = Menu::factory()->inStore($otherStore->id)->create();
    $theirs = dishOnMenu($otherStore->id, $theirMenu->id, 'Phở bò');

    // A half-applied sheet would promise portions nobody prepared, so one
    // unknown item fails the request and leaves every other line untouched.
    $this->actingAs($manager, 'sanctum')
        ->putJson('/api/v1/admin/menu-items/daily-portions', [
            'items' => [
                ['id' => $ours->id, 'prepared_quantity' => 40],
                ['id' => $theirs->id, 'prepared_quantity' => 25],
            ],
        ])
        ->assertNotFound()
        ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');

    expect($ours->refresh()->available_quantity)->toBe(5)
        ->and($ours->daily_capacity)->toBe(30)
        ->and($theirs->refresh()->available_quantity)->toBe(5);
});

it('refuses the same dish twice on one sheet', function (): void {
    [$store, $manager] = storeWithStaff();
    $menu = Menu::factory()->inStore($store->id)->create();
    $dish = dishOnMenu($store->id, $menu->id);

    // Forty or sixty? A sheet that says both has no correct answer.
    $this->actingAs($manager, 'sanctum')
        ->putJson('/api/v1/admin/menu-items/daily-portions', [
            'items' => [
                ['id' => $dish->id, 'prepared_quantity' => 40],
                ['id' => $dish->id, 'prepared_quantity' => 60],
            ],
        ])
        ->assertStatus(422);

    expect($dish->refresh()->available_quantity)->toBe(5);
});

it('refuses a negative or absurd number of portions', function (): void {
    [$store, $manager] = storeWithStaff();
    $menu = Menu::factory()->inStore($store->id)->create();
    $dish = dishOnMenu($store->id, $menu->id);

    foreach ([-1, 10_001] as $quantity) {
        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/v1/admin/menu-items/daily-portions', [
                'items' => [['id' => $dish->id, 'prepared_quantity' => $quantity]],
            ])
            ->assertStatus(422);
    }

    expect($dish->refresh()->available_quantity)->toBe(5);
});

it('refuses an empty sheet', function (): void {
    [, $manager] = storeWithStaff();

    $this->actingAs($manager, 'sanctum')
        ->putJson('/api/v1/admin/menu-items/daily-portions', ['items' => []])
        ->assertStatus(422);
});

it('lets kitchen staff write the prep sheet without a manager', function (): void {
    [$store, $kitchen] = storeWithStaff('KITCHEN_STAFF');
    $menu = Menu::factory()->inStore($store->id)->create();
    $dish = dishOnMenu($store->id, $menu->id);

    // Writing down what was cooked this morning is kitchen work.
    $this->actingAs($kitchen, 'sanctum')
        ->putJson('/api/v1/admin/menu-items/daily-portions', [
            'items' => [['id' => $dish->id, 'prepared_quantity' => 35]],
        ])
        ->assertOk()
        ->assertJsonPath('items.0.daily_capacity', 35);
});

it('refuses a cashier, who has neither menu nor kitchen rights', function (): void {
    [$store, $cashier] = storeWithStaff('CASHIER');
    $menu = Menu::factory()->inStore($store->id)->create();
    $dish = dishOnMenu($store->id, $menu->id);

    $this->actingAs($cashier, 'sanctum')
        ->putJson('/api/v1/admin/menu-items/daily-portions', [
            'items' => [['id' => $dish->id, 'prepared_quantity' => 35]],
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'STORE_SCOPE_DENIED');

    expect($dish->refresh()->available_quantity)->toBe(5);
});

it('records every line in the audit trail', function (): void {
    [$store, $manager] = storeWithStaff();
    $menu = Menu::factory()->inStore($store->id)->create();
    $first = dishOnMenu($store->id, $menu->id, 'Bánh mì pate trứng');
    $second = dishOnMenu($store->id, $menu->id, 'Xôi gà xé');

    $this->actingAs($manager, 'sanctum')
        ->putJson('/api/v1/admin/menu-items/daily-portions', [
            'items' => [
                ['id' => $first->id, 'prepared_quantity' => 40],
                ['id' => $second->id, 'prepared_quantity' => 30],
            ],
        ])
        ->assertOk();

    // A day's numbers have to be explainable afterwards, line by line.
    expect(AuditLog::query()->where('action', 'menu.item.daily-portions')->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'menu.item.daily-portions')->first()?->actor_id)
        ->toBe($manager->id);
});

it('refuses an unauthenticated caller', function (): void {
    $this->putJson('/api/v1/admin/menu-items/daily-portions', ['items' => []])
        ->assertUnauthorized();
});
