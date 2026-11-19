<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Enum\CatalogStatus;
use App\Modules\Catalog\Infrastructure\Persistence\Model\Category;
use App\Modules\Catalog\Infrastructure\Persistence\Model\Product;
use App\Modules\Catalog\Infrastructure\Persistence\Model\ProductVariant;
use App\Modules\Identity\Domain\Enum\MembershipStatus;
use App\Modules\Identity\Infrastructure\Persistence\Model\Role;
use App\Modules\Identity\Infrastructure\Persistence\Model\StoreMember;
use App\Modules\Identity\Infrastructure\Persistence\Model\User;
use App\Modules\Menu\Infrastructure\Persistence\Model\Menu;
use App\Modules\Menu\Infrastructure\Persistence\Model\MenuItem;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use Database\Seeders\RolePermissionSeeder;

/*
 * Admin catalog endpoints, against real PostgreSQL.
 *
 * SQLite could not run these: the duplicate-name rule is a partial unique
 * index, cross-store integrity is a composite foreign key, and both behave
 * differently or not at all there (CLAUDE.md section 12).
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/**
 * A store plus a staff member of it, with the store as the request's context.
 *
 * These routes carry no {store} parameter: context comes from the host map or
 * the single-store default, never from client input (CLAUDE.md section 8). A
 * test therefore configures the default the same way a single-store deployment
 * does.
 *
 * @return array{0: Store, 1: User}
 */
function storeWithStaff(string $roleCode = 'STORE_MANAGER'): array
{
    $store = Store::factory()->create();

    config(['commerce.default_store_slug' => $store->slug]);

    // The resolved store is a scoped container binding, which a real request
    // boundary resets and a test does not: without this, a second request in
    // the same test would try to resolve a different store into a context that
    // already holds one, and StoreContext rightly refuses that.
    app()->forgetScopedInstances();

    $user = User::factory()->create();

    StoreMember::query()->create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'role_id' => Role::query()->where('code', $roleCode)->sole()->id,
        'status' => MembershipStatus::Active->value,
    ]);

    return [$store, $user];
}

/**
 * A valid product payload, shaped like the admin menu form's.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function productPayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Bánh mì pate trứng',
        'status' => 'ACTIVE',
        'category_name' => 'Bánh mì',
        'icon' => 'banh-mi',
        'variants' => [
            ['name' => 'Mặc định', 'base_price_amount' => '25000', 'status' => 'ACTIVE'],
        ],
    ], $overrides);
}

/**
 * The payload that archives a product, which is what "delete" means here.
 *
 * @param  array<string, mixed>  $created
 * @return array<string, mixed>
 */
function archivePayload(array $created): array
{
    return [
        'name' => $created['name'],
        'status' => 'ARCHIVED',
        'category_id' => $created['category_id'],
        'icon' => $created['icon'],
        'variants' => array_map(static fn (array $variant): array => [
            'id' => $variant['id'],
            'name' => $variant['name'],
            'base_price_amount' => $variant['base_price']['amount'],
            'status' => 'ARCHIVED',
        ], $created['variants']),
    ];
}

it('creates a product with its variant and category, and puts nothing on sale', function (): void {
    [$store, $manager] = storeWithStaff();

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/products', productPayload())
        ->assertCreated()
        ->assertJsonPath('name', 'Bánh mì pate trứng')
        ->assertJsonPath('status', 'ACTIVE')
        ->assertJsonPath('category.name', 'Bánh mì')
        ->assertJsonPath('icon', 'banh-mi')
        ->assertJsonPath('variants.0.name', 'Mặc định')
        ->assertJsonPath('variants.0.base_price.amount', '25000')
        ->assertJsonPath('variants.0.base_price.currency', 'VND');

    expect(Product::query()->where('store_id', $store->id)->count())->toBe(1)
        ->and(Category::query()->where('store_id', $store->id)->count())->toBe(1)
        // Creating a product sells nothing anywhere: a menu decides that.
        ->and(MenuItem::query()->count())->toBe(0);
});

it('stores the price as whole dong in a bigint column', function (): void {
    [$store, $manager] = storeWithStaff();

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/products', productPayload([
            'variants' => [['name' => 'Mặc định', 'base_price_amount' => '9007199254740993', 'status' => 'ACTIVE']],
        ]))
        ->assertStatus(422); // Above the agreed ceiling, and rejected as such.

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/products', productPayload([
            'variants' => [['name' => 'Mặc định', 'base_price_amount' => '100000000', 'status' => 'ACTIVE']],
        ]))
        ->assertCreated();

    $variant = ProductVariant::query()->where('store_id', $store->id)->sole();

    expect($variant->base_price_amount)->toBe(100_000_000);
});

it('rejects a second product with the same name in the same store', function (): void {
    [, $manager] = storeWithStaff();

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/products', productPayload())
        ->assertCreated();

    // Case and spacing differences are the same name, which is what the admin
    // form says and what the partial unique index enforces.
    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/products', productPayload(['name' => '  bánh mì   PATE trứng ']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonPath('details.0.field', 'name');

    expect(Product::query()->count())->toBe(1);
});

it('allows the same name in a different store', function (): void {
    [, $managerA] = storeWithStaff();

    $this->actingAs($managerA, 'sanctum')
        ->postJson('/api/v1/admin/products', productPayload())
        ->assertCreated();

    // A second store's catalogue is its own; the name is not taken there.
    [, $managerB] = storeWithStaff();

    $this->actingAs($managerB, 'sanctum')
        ->postJson('/api/v1/admin/products', productPayload())
        ->assertCreated();

    expect(Product::query()->count())->toBe(2);
});

it('frees the name of an archived product for reuse', function (): void {
    [, $manager] = storeWithStaff();

    $created = $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/products', productPayload())
        ->assertCreated()
        ->json();

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/products/{$created['id']}", archivePayload($created))
        ->assertOk()
        ->assertJsonPath('status', 'ARCHIVED');

    // A retired dish must not hold its name hostage.
    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/products', productPayload())
        ->assertCreated();

    expect(Product::query()->count())->toBe(2);
});

it('rejects a negative price', function (): void {
    [, $manager] = storeWithStaff();

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/products', productPayload([
            'variants' => [['name' => 'Mặc định', 'base_price_amount' => '-25000', 'status' => 'ACTIVE']],
        ]))
        ->assertStatus(422)
        ->assertJsonPath('details.0.field', 'variants.0.base_price_amount');

    expect(Product::query()->count())->toBe(0);
});

it('rejects a price that is not whole dong', function (): void {
    [, $manager] = storeWithStaff();

    // "25000.50", "2.5e4" and "" all read as numbers to a naive parser, and
    // each would become a wrong price (CLAUDE.md section 6). A value with
    // surrounding whitespace is not in this list: the framework trims input
    // before validation, so " 25000" really is twenty-five thousand dong.
    foreach (['25000.50', '2.5e4', '', '25 000', '-0'] as $index => $amount) {
        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/admin/products', productPayload([
                'name' => 'Món thử '.$index,
                'variants' => [['name' => 'Mặc định', 'base_price_amount' => $amount, 'status' => 'ACTIVE']],
            ]))
            ->assertStatus(422);
    }

    expect(Product::query()->count())->toBe(0);
});

it('rejects category_id and category_name together', function (): void {
    [$store, $manager] = storeWithStaff();
    $category = Category::factory()->inStore($store->id)->create();

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/products', productPayload([
            'category_id' => $category->id,
            'category_name' => 'Bánh mì',
        ]))
        ->assertStatus(422);
});

it('refuses a category that belongs to another store', function (): void {
    [, $manager] = storeWithStaff();
    $otherStore = Store::factory()->create();
    $theirCategory = Category::factory()->inStore($otherStore->id)->create();

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/products', productPayload([
            'category_id' => $theirCategory->id,
            'category_name' => null,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('details.0.field', 'category_id');
});

it('reuses an existing category matched case-insensitively', function (): void {
    [$store, $manager] = storeWithStaff();
    Category::factory()->inStore($store->id)->create(['name' => 'Bánh mì', 'slug' => 'banh-mi']);

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/products', productPayload(['category_name' => 'BÁNH MÌ']))
        ->assertCreated();

    expect(Category::query()->where('store_id', $store->id)->count())->toBe(1);
});

it('keeps every record when a dish is removed from the dashboard', function (): void {
    [$store, $manager] = storeWithStaff();
    $menu = Menu::factory()->inStore($store->id)->create();

    $created = $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/products', productPayload())
        ->assertCreated()
        ->json();

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/menus/{$menu->id}", [
            'name' => $menu->name,
            'status' => 'ACTIVE',
            'schedules' => [],
            'items' => [[
                'variant_id' => $created['variants'][0]['id'],
                'selling_price_amount' => '30000',
                'is_available' => true,
                'available_quantity' => 10,
                'daily_capacity' => 20,
            ]],
        ])
        ->assertOk()
        ->assertJsonCount(1, 'items');

    // "Delete" in the dashboard archives the product.
    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/products/{$created['id']}", archivePayload($created))
        ->assertOk()
        ->assertJsonPath('status', 'ARCHIVED');

    // The dish is off the menu...
    $this->actingAs($manager, 'sanctum')
        ->getJson("/api/v1/admin/menus/{$menu->id}")
        ->assertOk()
        ->assertJsonCount(0, 'items');

    // ...and every row that recorded it survives, which is what keeps an old
    // receipt readable (CLAUDE.md section 7).
    expect(Product::query()->whereKey($created['id'])->exists())->toBeTrue()
        ->and(ProductVariant::query()->where('product_id', $created['id'])->count())->toBe(1)
        ->and(MenuItem::query()->where('menu_id', $menu->id)->count())->toBe(1);
});

it('archives a variant dropped from the set instead of deleting it', function (): void {
    [, $manager] = storeWithStaff();

    $created = $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/products', productPayload([
            'variants' => [
                ['name' => 'Nhỏ', 'base_price_amount' => '20000', 'status' => 'ACTIVE'],
                ['name' => 'Lớn', 'base_price_amount' => '30000', 'status' => 'ACTIVE'],
            ],
        ]))
        ->assertCreated()
        ->json();

    $keep = $created['variants'][0];

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/products/{$created['id']}", [
            'name' => $created['name'],
            'status' => 'ACTIVE',
            'category_id' => $created['category_id'],
            'variants' => [[
                'id' => $keep['id'],
                'name' => $keep['name'],
                'base_price_amount' => '21000',
                'status' => 'ACTIVE',
            ]],
        ])
        ->assertOk()
        // The response still carries the retired variant, with its status: the
        // admin screen manages master data, and hiding what was retired would
        // leave staff wondering where it went.
        ->assertJsonCount(2, 'variants');

    expect(ProductVariant::query()->where('product_id', $created['id'])->count())->toBe(2)
        ->and(ProductVariant::query()
            ->where('product_id', $created['id'])
            ->where('status', CatalogStatus::Archived->value)
            ->count())->toBe(1);
});

it('lists this store products only', function (): void {
    [$storeA, $managerA] = storeWithStaff();
    $storeB = Store::factory()->create();

    $ours = Product::factory()->inStore($storeA->id)->create(['name' => 'Xôi gà']);
    ProductVariant::factory()->forProduct($ours->id, $storeA->id)->create();

    $theirs = Product::factory()->inStore($storeB->id)->create(['name' => 'Phở bò']);
    ProductVariant::factory()->forProduct($theirs->id, $storeB->id)->create();

    $response = $this->actingAs($managerA, 'sanctum')
        ->getJson('/api/v1/admin/products')
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.name', 'Xôi gà');

    expect($response->json('has_more'))->toBeFalse()
        ->and($response->json('next_cursor'))->toBeNull();
});

it('pages through products with an opaque cursor', function (): void {
    [$storeA, $managerA] = storeWithStaff();

    foreach (range(1, 3) as $index) {
        $product = Product::factory()->inStore($storeA->id)->create(['name' => 'Món '.$index]);
        ProductVariant::factory()->forProduct($product->id, $storeA->id)->create();
    }

    $first = $this->actingAs($managerA, 'sanctum')
        ->getJson('/api/v1/admin/products?limit=2')
        ->assertOk()
        ->assertJsonCount(2, 'items')
        ->assertJsonPath('has_more', true);

    $second = $this->actingAs($managerA, 'sanctum')
        ->getJson('/api/v1/admin/products?limit=2&cursor='.urlencode((string) $first->json('next_cursor')))
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('has_more', false);

    // No row appears on both pages.
    $ids = array_merge(
        array_column((array) $first->json('items'), 'id'),
        array_column((array) $second->json('items'), 'id'),
    );

    expect($ids)->toHaveCount(3)->and(array_unique($ids))->toHaveCount(3);
});

it('reports another store product as not found rather than forbidden', function (): void {
    [, $managerA] = storeWithStaff();
    $storeB = Store::factory()->create();

    $theirs = Product::factory()->inStore($storeB->id)->create(['name' => 'Phở bò']);
    ProductVariant::factory()->forProduct($theirs->id, $storeB->id)->create();

    // 404, not 403: whether that id exists elsewhere is not this store's
    // information (CLAUDE.md section 8).
    $this->actingAs($managerA, 'sanctum')
        ->putJson("/api/v1/admin/products/{$theirs->id}", productPayload())
        ->assertNotFound()
        ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');

    expect($theirs->refresh()->name)->toBe('Phở bò');
});

it('refuses a role without the catalog permission', function (): void {
    [, $kitchen] = storeWithStaff('KITCHEN_STAFF');

    $this->actingAs($kitchen, 'sanctum')
        ->postJson('/api/v1/admin/products', productPayload())
        ->assertForbidden()
        ->assertJsonPath('code', 'STORE_SCOPE_DENIED');

    $this->actingAs($kitchen, 'sanctum')
        ->getJson('/api/v1/admin/products')
        ->assertForbidden();
});

it('refuses a manager of another store', function (): void {
    [$storeA] = storeWithStaff();
    $otherStore = Store::factory()->create();
    $outsider = User::factory()->create();

    StoreMember::query()->create([
        'store_id' => $otherStore->id,
        'user_id' => $outsider->id,
        'role_id' => Role::query()->where('code', 'STORE_MANAGER')->sole()->id,
        'status' => MembershipStatus::Active->value,
    ]);

    // The context is store A; this manager staffs another store entirely.
    config(['commerce.default_store_slug' => $storeA->slug]);

    $this->actingAs($outsider, 'sanctum')
        ->getJson('/api/v1/admin/products')
        ->assertForbidden()
        ->assertJsonPath('code', 'STORE_SCOPE_DENIED');
});

it('refuses an unauthenticated caller before touching the catalog', function (): void {
    storeWithStaff();

    $this->getJson('/api/v1/admin/products')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('refuses modifier groups, which are designed but not built', function (): void {
    [, $manager] = storeWithStaff();

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/products', productPayload([
            'modifier_group_ids' => ['01930000-0000-7000-8000-000000000000'],
        ]))
        ->assertStatus(422);
});
