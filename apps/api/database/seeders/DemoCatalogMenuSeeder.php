<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Catalog\Application\Action\CreateProduct;
use App\Modules\Catalog\Application\DTO\ProductInput;
use App\Modules\Catalog\Application\DTO\VariantInput;
use App\Modules\Catalog\Domain\Contract\ProductRepository;
use App\Modules\Catalog\Domain\Enum\CatalogStatus;
use App\Modules\Menu\Application\Action\ReplaceMenu;
use App\Modules\Menu\Application\DTO\MenuInput;
use App\Modules\Menu\Application\DTO\MenuItemInput;
use App\Modules\Menu\Domain\Entity\MenuItem;
use App\Modules\Menu\Infrastructure\Persistence\Model\Menu as MenuModel;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use App\Shared\Domain\ValueObject\Money;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * A real breakfast menu, for local development and staging.
 *
 * Why this exists. The customer storefront's first real slice (the home
 * screen) reads today's menu from the Catalog and Menu modules, and until now
 * nothing had ever put a realistic catalogue in either: `DefaultMenuSeeder`
 * only provisions an empty ACTIVE menu, and `DemoOrderSeeder` falls back to
 * name-only dishes with no product when the menu has nothing on it. This is
 * what gives both something real to show.
 *
 * It mirrors the storefront's own design sample
 * (`apps/customer-web/src/data/products.ts`) one for one - same dishes, same
 * prices, same packaged weights for the chả line - so replacing that file with
 * a real API call changes nothing a customer sees.
 *
 * Built through `CreateProduct` and `ReplaceMenu`, not raw inserts: a seeded
 * product is not exempt from the rules a real product has to satisfy (CLAUDE.md
 * section 16), and going through the same use cases an admin screen calls is
 * what keeps this from silently drifting out of sync with them.
 *
 * Idempotent by product name: a store that already sells "Xôi gà xé" keeps it
 * exactly as it is (price included, if a store manager has since changed it)
 * rather than fighting the seeder for it on every run.
 *
 * Never runs in production: DatabaseSeeder gates it, and this class refuses
 * anyway. An invented breakfast menu in a real shop's database would be sold
 * to real customers.
 */
final class DemoCatalogMenuSeeder extends Seeder
{
    /**
     * @var list<array{
     *     name: string,
     *     category: string,
     *     image: string,
     *     price?: int,
     *     variants?: list<array{label: string, price: int}>,
     * }>
     */
    private const array DISHES = [
        ['name' => 'Bánh mì pate trứng', 'category' => 'Bánh mì', 'image' => 'food-1.jpg', 'price' => 20_000],
        ['name' => 'Bánh mì pate xá xíu', 'category' => 'Bánh mì', 'image' => 'food-2.jpg', 'price' => 28_000],
        ['name' => 'Bánh mì gà nướng', 'category' => 'Bánh mì', 'image' => 'food-1.jpg', 'price' => 25_000],
        ['name' => 'Bún bò Huế', 'category' => 'Món chính', 'image' => 'food-3.jpg', 'price' => 45_000],
        ['name' => 'Xôi gà xé', 'category' => 'Xôi', 'image' => 'food-4.jpg', 'price' => 30_000],
        ['name' => 'Cà phê sữa đá', 'category' => 'Đồ uống', 'image' => 'food-5.jpg', 'price' => 18_000],
        ['name' => 'Trà tắc mật ong', 'category' => 'Đồ uống', 'image' => 'food-6.jpg', 'price' => 22_000],
        [
            'name' => 'Chả ốc',
            'category' => 'Chả đặc sản',
            'image' => 'food-7.jpg',
            'variants' => [
                ['label' => '250g', 'price' => 62_500],
                ['label' => '500g', 'price' => 125_000],
                ['label' => '1kg', 'price' => 250_000],
            ],
        ],
        [
            'name' => 'Chả tôm',
            'category' => 'Chả đặc sản',
            'image' => 'food-8.jpg',
            'variants' => [
                ['label' => '250g', 'price' => 70_000],
                ['label' => '500g', 'price' => 140_000],
                ['label' => '1kg', 'price' => 280_000],
            ],
        ],
        [
            'name' => 'Chả mực',
            'category' => 'Chả đặc sản',
            'image' => 'food-9.jpg',
            'variants' => [
                ['label' => '250g', 'price' => 80_000],
                ['label' => '500g', 'price' => 160_000],
                ['label' => '1kg', 'price' => 320_000],
            ],
        ],
        [
            'name' => 'Chả cá thát lát',
            'category' => 'Chả đặc sản',
            'image' => 'food-10.jpg',
            'variants' => [
                ['label' => '250g', 'price' => 65_000],
                ['label' => '500g', 'price' => 130_000],
                ['label' => '1kg', 'price' => 260_000],
            ],
        ],
        [
            'name' => 'Chả cua',
            'category' => 'Chả đặc sản',
            'image' => 'food-11.jpg',
            'variants' => [
                ['label' => '250g', 'price' => 75_000],
                ['label' => '500g', 'price' => 150_000],
                ['label' => '1kg', 'price' => 300_000],
            ],
        ],
        [
            'name' => 'Chả cốm',
            'category' => 'Chả đặc sản',
            'image' => 'food-12.jpg',
            'variants' => [
                ['label' => '250g', 'price' => 57_500],
                ['label' => '500g', 'price' => 115_000],
                ['label' => '1kg', 'price' => 230_000],
            ],
        ],
        ['name' => 'Combo bánh mì + cà phê', 'category' => 'Combo', 'image' => 'food-2.jpg', 'price' => 35_000],
        ['name' => 'Chả ốc chiên ăn liền', 'category' => 'Ăn vặt', 'image' => 'food-7.jpg', 'price' => 45_000],
    ];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly CreateProduct $createProduct,
        private readonly ReplaceMenu $replaceMenu,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoCatalogMenuSeeder must never run in production');
        }

        Store::query()->each(function (Store $store): void {
            $menu = MenuModel::query()->where('store_id', $store->id)->first();

            if (! $menu instanceof MenuModel) {
                // DefaultMenuSeeder provisions one for every store; nothing
                // here can sell without it.
                return;
            }

            $existingItems = [];
            foreach ($menu->items()->listed()->get() as $item) {
                $existingItems[] = MenuItem::create(
                    $item->id,
                    $item->product_variant_id,
                    Money::vnd($item->selling_price_amount),
                    $item->is_available,
                    $item->available_quantity,
                    $item->daily_capacity,
                    $item->sort_order,
                );
            }
            $seenVariantIds = array_flip(array_map(
                static fn (MenuItem $item): string => $item->variantId,
                $existingItems,
            ));

            $sortOrder = count($existingItems);
            $newItems = [];

            foreach (self::DISHES as $dish) {
                // Idempotent by name: a dish this store already sells (seeded
                // before, or added by hand since) is never re-created or
                // repriced.
                if ($this->products->liveNameExists($store->id, $dish['name'])) {
                    continue;
                }

                $variantInputs = isset($dish['variants'])
                    ? array_map(
                        static fn (array $variant): VariantInput => new VariantInput(
                            id: null,
                            sku: null,
                            name: $variant['label'],
                            basePrice: Money::vnd($variant['price']),
                            status: CatalogStatus::Active,
                        ),
                        $dish['variants'],
                    )
                    : [new VariantInput(
                        id: null,
                        sku: null,
                        name: 'Mặc định',
                        basePrice: Money::vnd($dish['price']),
                        status: CatalogStatus::Active,
                    )];

                $product = $this->createProduct->handle($store->id, new ProductInput(
                    name: $dish['name'],
                    status: CatalogStatus::Active,
                    variants: $variantInputs,
                    categoryName: $dish['category'],
                    imageUrl: '/assets/'.$dish['image'],
                ));

                foreach ($product->variants as $variant) {
                    if ($variant->id === null || isset($seenVariantIds[$variant->id])) {
                        continue;
                    }

                    $newItems[] = new MenuItemInput(
                        variantId: $variant->id,
                        sellingPrice: $variant->basePrice,
                        sortOrder: $sortOrder++,
                    );
                }
            }

            if ($newItems === []) {
                return;
            }

            $this->replaceMenu->handle($store->id, $menu->id, new MenuInput(
                name: $menu->name,
                status: $menu->status,
                items: [
                    ...array_map(
                        static fn (MenuItem $item): MenuItemInput => new MenuItemInput(
                            variantId: $item->variantId,
                            sellingPrice: $item->price,
                            isAvailable: $item->isAvailable,
                            availableQuantity: $item->availableQuantity,
                            dailyCapacity: $item->dailyCapacity,
                            sortOrder: $item->sortOrder,
                            id: $item->id,
                            quantityGiven: $item->availableQuantity !== null,
                            capacityGiven: $item->dailyCapacity !== null,
                        ),
                        $existingItems,
                    ),
                    ...$newItems,
                ],
            ));
        });

        $this->command?->info('Demo catalogue and menu seeded for every store (local and staging only).');
    }
}
