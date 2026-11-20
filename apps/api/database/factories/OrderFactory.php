<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Order\Domain\Enum\FulfillmentType;
use App\Modules\Order\Domain\Enum\OrderStatus;
use App\Modules\Order\Infrastructure\Persistence\Model\Order;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * An order with no lines and no money, waiting to be confirmed.
     *
     * Zero totals rather than invented ones: the amounts have to agree with
     * the lines and with each other - the database checks both - so a test
     * states them together through `totalling()` or builds lines and lets the
     * helper sum them.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            // Unique platform-wide, and the format staff read out loud.
            'order_code' => sprintf('SCP-%s-%s', now()->format('Ymd'), Str::upper(Str::random(6))),
            'customer_id' => null,
            'contact_name' => 'Khách lẻ',
            'contact_phone' => '0900000000',
            'contact_email' => null,
            'status' => OrderStatus::Placed,
            'fulfillment_type' => FulfillmentType::Pickup,
            'scheduled_at' => null,
            'delivery_address_line' => null,
            'note' => null,
            'subtotal_amount' => 0,
            'discount_amount' => 0,
            'delivery_fee_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'currency' => 'VND',
            'placed_at' => now()->toImmutable(),
        ];
    }

    public function inStore(string $storeId): static
    {
        return $this->state(fn (): array => ['store_id' => $storeId]);
    }

    /**
     * Puts the order in a given status, keeping `placed_at` consistent with it.
     *
     * The database enforces the rule - an order awaiting payment was never
     * placed, one in the service flow was - so a factory that ignored it would
     * fail at insert time with a constraint error instead of a readable test.
     */
    public function status(OrderStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'placed_at' => $status === OrderStatus::PendingPayment ? null : now()->toImmutable(),
        ]);
    }

    /** The money, with the total derived the same way the CHECK derives it. */
    public function totalling(int $subtotal, int $discount = 0, int $deliveryFee = 0, int $tax = 0): static
    {
        return $this->state(fn (): array => [
            'subtotal_amount' => $subtotal,
            'discount_amount' => $discount,
            'delivery_fee_amount' => $deliveryFee,
            'tax_amount' => $tax,
            'total_amount' => $subtotal - $discount + $deliveryFee + $tax,
        ]);
    }

    /** Delivery needs an address; the database refuses one without it. */
    public function delivery(string $addressLine = '12 Nguyễn Huệ, Quận 1'): static
    {
        return $this->state(fn (): array => [
            'fulfillment_type' => FulfillmentType::Delivery,
            'delivery_address_line' => $addressLine,
        ]);
    }

    public function forCustomer(string $userId, string $name = 'Nguyễn Minh Anh'): static
    {
        return $this->state(fn (): array => [
            'customer_id' => $userId,
            'contact_name' => $name,
        ]);
    }
}
