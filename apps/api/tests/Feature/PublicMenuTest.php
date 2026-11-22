<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Enum\CatalogStatus;
use App\Modules\Catalog\Infrastructure\Persistence\Model\Category;
use App\Modules\Catalog\Infrastructure\Persistence\Model\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Model\ProductVariant;
use App\Modules\Menu\Domain\Enum\MenuStatus;
use App\Modules\Menu\Infrastructure\Persistence\Model\Menu;
use App\Modules\Menu\Infrastructure\Persistence\Model\MenuItem;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use App\Shared\Domain\ValueObject\DisplayName;
use Illuminate\Support\Str;

/*
 * The customer storefront's home screen: today's menu.
 *
 * Public - no session, no permission - so these tests never call actingAs.
 * Against real PostgreSQL, like every other feature test: the UUID and
 * composite foreign keys here are exactly what SQLite's looser typing would
 * let slide.
 *
 * Shaped to the designed `TodayMenu` contract minus the schedule fields that
 * contract reserves for a slice not yet built - see PublicMenuController.
 */

/** One listable product, one variant, one menu item - the minimum an item needs. */
function menuItemFor(
    string $storeId,
    string $menuId,
    string $categoryName,
    string $productName,
    int $price,
    int $sortOrder = 0,
): MenuItem {
    $category = Category::query()->firstOrCreate(
        ['store_id' => $storeId, 'slug' => Str::slug($categoryName)],
        ['name' => $categoryName, 'name_key' => DisplayName::key($categoryName)],
    );

    $product = Product::query()->create([
        'store_id' => $storeId,
        'category_id' => $category->id,
        'name' => $productName,
        'name_key' => DisplayName::key($productName),
        'slug' => Str::slug($productName),
        'status' => CatalogStatus::Active->value,
        'inventory_tracking' => true,
    ]);

    $variant = ProductVariant::query()->create([
        'store_id' => $storeId,
        'product_id' => $product->id,
        'name' => 'Mặc định',
        'name_key' => DisplayName::key('Mặc định'),
        'base_price_amount' => $price,
        'status' => CatalogStatus::Active->value,
    ]);

    return MenuItem::query()->create([
        'store_id' => $storeId,
        'menu_id' => $menuId,
        'product_variant_id' => $variant->id,
        'selling_price_amount' => $price,
        'is_available' => true,
        'sort_order' => $sortOrder,
    ]);
}

it('returns the store active menu, store-local service date and items', function (): void {
    $store = Store::factory()->create(['timezone' => 'Asia/Ho_Chi_Minh']);
    $activeMenu = Menu::query()->create([
        'store_id' => $store->id,
        'name' => 'Thực đơn hằng ngày',
        'name_key' => 'thuc-don-hang-ngay',
        'status' => MenuStatus::Active->value,
    ]);
    $draftMenu = Menu::query()->create([
        'store_id' => $store->id,
        'name' => 'Thực đơn mùa hè',
        'name_key' => 'thuc-don-mua-he',
        'status' => MenuStatus::Draft->value,
    ]);

    menuItemFor($store->id, $activeMenu->id, 'Bánh mì', 'Bánh mì pate trứng', 20_000, 0);
    menuItemFor($store->id, $activeMenu->id, 'Đồ uống', 'Cà phê sữa đá', 18_000, 1);
    // On the DRAFT menu only - must never appear in the public response.
    menuItemFor($store->id, $draftMenu->id, 'Xôi', 'Xôi gà xé', 30_000, 0);

    config(['commerce.default_store_slug' => $store->slug]);

    $response = $this->getJson('/api/v1/menus/today')
        ->assertOk()
        ->assertJsonPath('store_id', $store->id)
        ->assertJsonPath('menu_id', $activeMenu->id)
        ->assertJsonPath('name', 'Thực đơn hằng ngày')
        ->assertJsonPath('timezone', 'Asia/Ho_Chi_Minh')
        ->assertJsonPath('served_from', null)
        ->assertJsonPath('served_until', null)
        ->assertJsonCount(2, 'items');

    expect($response->json('service_date'))->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and(collect($response->json('items'))->pluck('product.name'))
        ->toContain('Bánh mì pate trứng', 'Cà phê sữa đá')
        ->not->toContain('Xôi gà xé');
});

it('hides an item whose product has been archived', function (): void {
    $store = Store::factory()->create();
    $menu = Menu::query()->create([
        'store_id' => $store->id,
        'name' => 'Thực đơn hằng ngày',
        'name_key' => 'thuc-don-hang-ngay',
        'status' => MenuStatus::Active->value,
    ]);
    $item = menuItemFor($store->id, $menu->id, 'Bánh mì', 'Bánh mì pate trứng', 20_000);
    Product::query()->whereHas('variants', fn ($q) => $q->where('id', $item->product_variant_id))
        ->update(['status' => CatalogStatus::Archived->value]);

    config(['commerce.default_store_slug' => $store->slug]);

    $this->getJson('/api/v1/menus/today')
        ->assertOk()
        ->assertJsonCount(0, 'items');
});

it('reports a store with no active menu as not found', function (): void {
    $store = Store::factory()->create();
    Menu::query()->create([
        'store_id' => $store->id,
        'name' => 'Thực đơn hằng ngày',
        'name_key' => 'thuc-don-hang-ngay',
        'status' => MenuStatus::Draft->value,
    ]);

    config(['commerce.default_store_slug' => $store->slug]);

    $this->getJson('/api/v1/menus/today')
        ->assertNotFound()
        ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
});

it('needs no session at all', function (): void {
    $store = Store::factory()->create();
    Menu::query()->create([
        'store_id' => $store->id,
        'name' => 'Thực đơn hằng ngày',
        'name_key' => 'thuc-don-hang-ngay',
        'status' => MenuStatus::Active->value,
    ]);

    config(['commerce.default_store_slug' => $store->slug]);

    $this->getJson('/api/v1/menus/today')->assertOk();
});
