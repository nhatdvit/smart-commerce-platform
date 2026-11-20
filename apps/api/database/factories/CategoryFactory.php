<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Infrastructure\Persistence\Model\Category;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use App\Shared\Domain\ValueObject\DisplayName;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
final class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = Str::ucfirst(implode(' ', (array) fake()->unique()->words(2)));

        return [
            // Every catalog row is store-scoped, so a category without a store
            // is not something this factory can make.
            'store_id' => Store::factory(),
            'parent_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'sort_order' => 0,
        ];
    }

    /**
     * The comparison key is derived, never passed in.
     *
     * Derived after the overrides are applied, so a test that sets a name gets
     * a matching key: the unique index is on this column, and a factory that
     * could write an inconsistent one would make the index lie.
     */
    public function configure(): static
    {
        return $this->afterMaking(static function (Category $category): void {
            $category->name_key = DisplayName::key($category->name);
        });
    }

    public function inStore(string $storeId): static
    {
        return $this->state(fn (): array => ['store_id' => $storeId]);
    }
}
