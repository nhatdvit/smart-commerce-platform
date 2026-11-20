<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Order\Infrastructure\Persistence\Model\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
final class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => null,
            'product_variant_id' => null,
            'product_name' => 'Bánh mì pate trứng',
            'variant_name' => 'Mặc định',
            'quantity' => 1,
            'unit_price_amount' => 25_000,
            'modifiers_total_amount' => 0,
            'currency' => 'VND',
            'note' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * The line total is derived after overrides, never passed in.
     *
     * The same formula is a CHECK constraint on `order_items`, so a factory
     * that let a test state its own total would fail at insert time rather
     * than tell the test anything useful.
     */
    public function configure(): static
    {
        return $this->afterMaking(static function (OrderItem $item): void {
            $item->line_total_amount =
                ($item->unit_price_amount + $item->modifiers_total_amount) * $item->quantity;
        });
    }

    /** order and store are one decision: the composite foreign key requires it. */
    public function on(string $orderId, string $storeId): static
    {
        return $this->state(fn (): array => [
            'order_id' => $orderId,
            'store_id' => $storeId,
        ]);
    }

    /** The snapshot of a dish that was really sold, with its master-data ids. */
    public function of(string $productId, string $variantId, string $name, int $unitPrice): static
    {
        return $this->state(fn (): array => [
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'product_name' => $name,
            'unit_price_amount' => $unitPrice,
        ]);
    }

    public function quantity(int $quantity): static
    {
        return $this->state(fn (): array => ['quantity' => $quantity]);
    }
}
