<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Domain\Enum\CatalogStatus;
use App\Modules\Catalog\Infrastructure\Persistence\Model\Product;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use App\Shared\Domain\ValueObject\DisplayName;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = Str::ucfirst(implode(' ', (array) fake()->unique()->words(2)));

        return [
            'store_id' => Store::factory(),
            'category_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'description' => null,
            'status' => CatalogStatus::Active,
            'inventory_tracking' => true,
            'image_url' => null,
            'icon' => 'banh-mi',
            'structured_attributes' => [],
        ];
    }

    /** The comparison key is derived after overrides, never passed in. */
    public function configure(): static
    {
        return $this->afterMaking(static function (Product $product): void {
            $product->name_key = DisplayName::key($product->name);
        });
    }

    public function inStore(string $storeId): static
    {
        return $this->state(fn (): array => ['store_id' => $storeId]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => CatalogStatus::Archived]);
    }
}
