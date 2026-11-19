<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Domain\Enum\CatalogStatus;
use App\Modules\Catalog\Infrastructure\Persistence\Model\ProductVariant;
use App\Shared\Domain\ValueObject\DisplayName;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
final class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // store_id and product_id are set together by the caller: the
            // composite foreign key refuses a variant whose store does not
            // match its product, which is the point of that key.
            'sku' => null,
            'name' => 'Mặc định',
            'base_price_amount' => 25_000,
            'currency' => 'VND',
            'status' => CatalogStatus::Active,
            'sort_order' => 0,
        ];
    }

    /** The comparison key is derived after overrides, never passed in. */
    public function configure(): static
    {
        return $this->afterMaking(static function (ProductVariant $variant): void {
            $variant->name_key = DisplayName::key($variant->name);
        });
    }

    public function forProduct(string $productId, string $storeId): static
    {
        return $this->state(fn (): array => [
            'product_id' => $productId,
            'store_id' => $storeId,
        ]);
    }

    public function priced(int $amount): static
    {
        return $this->state(fn (): array => ['base_price_amount' => $amount]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => CatalogStatus::Archived]);
    }
}
