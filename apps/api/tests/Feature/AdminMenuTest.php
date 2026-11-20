<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Persistence\Model\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Model\ProductVariant;
use App\Modules\Menu\Infrastructure\Persistence\Model\Menu;
use App\Modules\Menu\Infrastructure\Persistence\Model\MenuItem;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use Database\Seeders\RolePermissionSeeder;

/*
 * Admin menu endpoints: what is offered, at what price, and how much is left.
 *
 * `storeWithStaff()` and the payload helpers live in AdminProductTest.php;
 * Pest loads every test file in the suite, so they are shared rather than
 * duplicated.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/**
 * A listable variant in one store, with its product.
 *
 * @return array{0: Product, 1: ProductVariant}
 */
function listableVariant(string $storeId, string $name = 'Xôi gà', int $price = 25_000): array
{
    $product = Product::factory()->inStore($storeId)->create(['name' => $name]);
    $variant = ProductVariant::factory()
        ->forProduct($product->id, $storeId)
        ->priced($price)
        ->create();

    return [$product, $variant];
}

/**
 * The menu replacement payload.
 *
 * @param  list<array<string, mixed>>  $items
 * @return array<string, mixed>
 */
function menuPayload(Menu $menu, array $items): array
{
    return [
        'name' => $menu->name,
        'status' => 'ACTIVE',
        'schedules' => [],
        'items' => $items,
    ];
}

it('lists the menus of this store only', function (): void {
    [$storeA, $manager] = storeWithStaff();
    $ours = Menu::factory()->inStore($storeA->id)->create(['name' => 'Thực đơn sáng']);
    Menu::factory()->inStore(Store::factory()->create()->id)->create(['name' => 'Thực đơn của tiệm khác']);

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/menus')
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.id', $ours->id)
        ->assertJsonPath('items.0.name', 'Thực đơn sáng')
        // Windows and date exceptions are designed but not stored yet: an
        // empty list is the truthful answer, not a missing field.
        ->assertJsonPath('items.0.schedules', [])
        ->assertJsonPath('has_more', false);
});

it('puts a product on the menu at the store price', function (): void {
    [$store, $manager] = storeWithStaff();
    $menu = Menu::factory()->inStore($store->id)->create();
    [$product, $variant] = listableVariant($store->id);

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/menus/{$menu->id}", menuPayload($menu, [[
            'variant_id' => $variant->id,
            // The menu price overrides the variant's master-data default.
            'selling_price_amount' => '32000',
            'is_available' => true,
            'available_quantity' => 12,
            'daily_capacity' => 30,
        ]]))
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.product.id', $product->id)
        ->assertJsonPath('items.0.product.name', 'Xôi gà')
        ->assertJsonPath('items.0.variant.id', $variant->id)
        ->assertJsonPath('items.0.price.amount', '32000')
        ->assertJsonPath('items.0.available_quantity', 12)
        ->assertJsonPath('items.0.daily_capacity', 30);

    // The variant's own price is untouched: the menu decides what is charged.
    expect($variant->refresh()->base_price_amount)->toBe(25_000);
});

it('takes an omitted item off the menu without deleting the row', function (): void {
    [$store, $manager] = storeWithStaff();
    $menu = Menu::factory()->inStore($store->id)->create();
    [, $keep] = listableVariant($store->id, 'Xôi gà');
    [, $drop] = listableVariant($store->id, 'Phở bò');

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/menus/{$menu->id}", menuPayload($menu, [
            ['variant_id' => $keep->id, 'selling_price_amount' => '25000'],
            ['variant_id' => $drop->id, 'selling_price_amount' => '45000'],
        ]))
        ->assertOk()
        ->assertJsonCount(2, 'items');

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/menus/{$menu->id}", menuPayload($menu, [
            ['variant_id' => $keep->id, 'selling_price_amount' => '25000'],
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'items');

    $removed = MenuItem::query()->where('product_variant_id', $drop->id)->sole();

    expect($removed->deleted_at)->not->toBeNull()
        ->and(MenuItem::query()->where('menu_id', $menu->id)->count())->toBe(2);
});

it('restores the same row when a variant is listed again', function (): void {
    [$store, $manager] = storeWithStaff();
    $menu = Menu::factory()->inStore($store->id)->create();
    [, $variant] = listableVariant($store->id);

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/menus/{$menu->id}", menuPayload($menu, [
            ['variant_id' => $variant->id, 'selling_price_amount' => '25000'],
        ]))
        ->assertOk();

    $originalId = MenuItem::query()->where('product_variant_id', $variant->id)->sole()->id;

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/menus/{$menu->id}", menuPayload($menu, []))
        ->assertOk();

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/menus/{$menu->id}", menuPayload($menu, [
            ['variant_id' => $variant->id, 'selling_price_amount' => '27000'],
        ]))
        ->assertOk()
        ->assertJsonPath('items.0.id', $originalId)
        ->assertJsonPath('items.0.price.amount', '27000');

    // One row for this listing, ever: its history stays in one place.
    expect(MenuItem::query()->where('menu_id', $menu->id)->count())->toBe(1);
});

it('leaves today portions alone when a menu edit does not mention them', function (): void {
    [$store, $manager] = storeWithStaff();
    $menu = Menu::factory()->inStore($store->id)->create();
    [, $variant] = listableVariant($store->id);

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/menus/{$menu->id}", menuPayload($menu, [[
            'variant_id' => $variant->id,
            'selling_price_amount' => '25000',
            'available_quantity' => 8,
            'daily_capacity' => 30,
        ]]))
        ->assertOk();

    // A price change must not reset what the kitchen counted.
    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/menus/{$menu->id}", menuPayload($menu, [[
            'variant_id' => $variant->id,
            'selling_price_amount' => '26000',
        ]]))
        ->assertOk()
        ->assertJsonPath('items.0.price.amount', '26000')
        ->assertJsonPath('items.0.available_quantity', 8)
        ->assertJsonPath('items.0.daily_capacity', 30);
});

it('refuses a variant from another store', function (): void {
    [$store, $manager] = storeWithStaff();
    $menu = Menu::factory()->inStore($store->id)->create();
    [, $theirVariant] = listableVariant(Store::factory()->create()->id);

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/menus/{$menu->id}", menuPayload($menu, [
            ['variant_id' => $theirVariant->id, 'selling_price_amount' => '25000'],
        ]))
        ->assertStatus(422)
        ->assertJsonPath('details.0.field', 'items.0.variant_id');

    expect(MenuItem::query()->count())->toBe(0);
});

it('refuses an archived variant', function (): void {
    [$store, $manager] = storeWithStaff();
    $menu = Menu::factory()->inStore($store->id)->create();
    $product = Product::factory()->inStore($store->id)->create();
    $variant = ProductVariant::factory()
        ->forProduct($product->id, $store->id)
        ->archived()
        ->create();

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/menus/{$menu->id}", menuPayload($menu, [
            ['variant_id' => $variant->id, 'selling_price_amount' => '25000'],
        ]))
        ->assertStatus(422)
        ->assertJsonPath('details.0.field', 'items.0.variant_id');
});

it('refuses a negative menu price', function (): void {
    [$store, $manager] = storeWithStaff();
    $menu = Menu::factory()->inStore($store->id)->create();
    [, $variant] = listableVariant($store->id);

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/menus/{$menu->id}", menuPayload($menu, [
            ['variant_id' => $variant->id, 'selling_price_amount' => '-5000'],
        ]))
        ->assertStatus(422);

    expect(MenuItem::query()->count())->toBe(0);
});

it('refuses more portions left than were prepared', function (): void {
    [$store, $manager] = storeWithStaff();
    $menu = Menu::factory()->inStore($store->id)->create();
    [, $variant] = listableVariant($store->id);

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/menus/{$menu->id}", menuPayload($menu, [[
            'variant_id' => $variant->id,
            'selling_price_amount' => '25000',
            'available_quantity' => 40,
            'daily_capacity' => 30,
        ]]))
        ->assertStatus(422)
        ->assertJsonPath('details.0.field', 'available_quantity');
});

it('refuses the same variant twice on one menu', function (): void {
    [$store, $manager] = storeWithStaff();
    $menu = Menu::factory()->inStore($store->id)->create();
    [, $variant] = listableVariant($store->id);

    // One variant with two prices on one menu has no correct answer.
    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/menus/{$menu->id}", menuPayload($menu, [
            ['variant_id' => $variant->id, 'selling_price_amount' => '25000'],
            ['variant_id' => $variant->id, 'selling_price_amount' => '30000'],
        ]))
        ->assertStatus(422);
});

it('refuses schedules, which are designed but not stored yet', function (): void {
    [$store, $manager] = storeWithStaff();
    $menu = Menu::factory()->inStore($store->id)->create();

    // Accepting a window nothing enforces would promise a menu that closes.
    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/menus/{$menu->id}", [
            'name' => $menu->name,
            'status' => 'ACTIVE',
            'schedules' => [['day_of_week' => 1, 'start_time' => '06:00', 'end_time' => '11:00']],
            'items' => [],
        ])
        ->assertStatus(422);
});

it('reports another store menu as not found', function (): void {
    [, $manager] = storeWithStaff();
    $theirMenu = Menu::factory()->inStore(Store::factory()->create()->id)->create();

    $this->actingAs($manager, 'sanctum')
        ->getJson("/api/v1/admin/menus/{$theirMenu->id}")
        ->assertNotFound()
        ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/menus/{$theirMenu->id}", menuPayload($theirMenu, []))
        ->assertNotFound();
});

it('lets staff mark an item sold out and set the portions left', function (): void {
    [$store, $manager] = storeWithStaff();
    $menu = Menu::factory()->inStore($store->id)->create();
    [, $variant] = listableVariant($store->id);
    $item = MenuItem::factory()->on($menu->id, $variant->id, $store->id)->create();

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/menu-items/{$item->id}/availability", [
            'is_available' => false,
            'available_quantity' => 0,
        ])
        ->assertOk()
        ->assertJsonPath('is_available', false)
        ->assertJsonPath('available_quantity', 0)
        // Untouched, because the request said nothing about it.
        ->assertJsonPath('daily_capacity', 30);
});

it('lets kitchen staff flip availability without a manager', function (): void {
    [$store, $kitchen] = storeWithStaff('KITCHEN_STAFF');
    $menu = Menu::factory()->inStore($store->id)->create();
    [, $variant] = listableVariant($store->id);
    $item = MenuItem::factory()->on($menu->id, $variant->id, $store->id)->create();

    // The most-used action in a working kitchen must not need a manager.
    $this->actingAs($kitchen, 'sanctum')
        ->putJson("/api/v1/admin/menu-items/{$item->id}/availability", ['is_available' => false])
        ->assertOk()
        ->assertJsonPath('is_available', false);
});

it('refuses a cashier, who has neither menu nor kitchen rights', function (): void {
    [$store, $cashier] = storeWithStaff('CASHIER');
    $menu = Menu::factory()->inStore($store->id)->create();
    [, $variant] = listableVariant($store->id);
    $item = MenuItem::factory()->on($menu->id, $variant->id, $store->id)->create();

    $this->actingAs($cashier, 'sanctum')
        ->putJson("/api/v1/admin/menu-items/{$item->id}/availability", ['is_available' => false])
        ->assertForbidden()
        ->assertJsonPath('code', 'STORE_SCOPE_DENIED');

    expect($item->refresh()->is_available)->toBeTrue();
});

it('refuses to change an item in another store', function (): void {
    [, $manager] = storeWithStaff();
    $otherStore = Store::factory()->create();
    $theirMenu = Menu::factory()->inStore($otherStore->id)->create();
    [, $theirVariant] = listableVariant($otherStore->id);
    $theirItem = MenuItem::factory()->on($theirMenu->id, $theirVariant->id, $otherStore->id)->create();

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/menu-items/{$theirItem->id}/availability", ['is_available' => false])
        ->assertNotFound()
        ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');

    expect($theirItem->refresh()->is_available)->toBeTrue();
});

it('refuses more portions left than prepared on the availability endpoint', function (): void {
    [$store, $manager] = storeWithStaff();
    $menu = Menu::factory()->inStore($store->id)->create();
    [, $variant] = listableVariant($store->id);
    $item = MenuItem::factory()->on($menu->id, $variant->id, $store->id)->create([
        'available_quantity' => 5,
        'daily_capacity' => 10,
    ]);

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/menu-items/{$item->id}/availability", [
            'is_available' => true,
            'available_quantity' => 11,
        ])
        ->assertStatus(422);

    expect($item->refresh()->available_quantity)->toBe(5);
});

it('refuses an unauthenticated caller', function (): void {
    [$store] = storeWithStaff();
    $menu = Menu::factory()->inStore($store->id)->create();

    $this->getJson("/api/v1/admin/menus/{$menu->id}")->assertUnauthorized();
    $this->putJson("/api/v1/admin/menus/{$menu->id}", menuPayload($menu, []))->assertUnauthorized();
});
