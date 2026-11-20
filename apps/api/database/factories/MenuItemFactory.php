<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Menu\Infrastructure\Persistence\Model\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuItem>
 */
final class MenuItemFactory extends Factory
{
    protected $model = MenuItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'selling_price_amount' => 25_000,
            'currency' => 'VND',
            'is_available' => true,
            'available_quantity' => 20,
            'daily_capacity' => 30,
            'sort_order' => 0,
            'deleted_at' => null,
        ];
    }

    /** menu, variant and store are one decision: the composite keys require it. */
    public function on(string $menuId, string $variantId, string $storeId): static
    {
        return $this->state(fn (): array => [
            'menu_id' => $menuId,
            'product_variant_id' => $variantId,
            'store_id' => $storeId,
        ]);
    }
}
