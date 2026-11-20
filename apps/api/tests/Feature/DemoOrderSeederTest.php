<?php

declare(strict_types=1);

use App\Modules\Order\Domain\Enum\OrderStatus;
use App\Modules\Order\Infrastructure\Persistence\Model\Order;
use App\Modules\Order\Infrastructure\Persistence\Model\OrderItem;
use App\Modules\Order\Infrastructure\Persistence\Model\OrderStatusChange;
use App\Modules\Payment\Domain\Enum\PaymentStatus;
use App\Modules\Payment\Infrastructure\Persistence\Model\Payment;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use Database\Seeders\DemoOrderSeeder;
use Database\Seeders\RolePermissionSeeder;

/*
 * The demo orders are the only way an order reaches the database until the
 * customer checkout lands, so they are worth a test: they write rows that the
 * CHECK constraints judge the same way they will judge checkout's. A seeder
 * that silently stopped producing a valid morning would leave the operations
 * queue empty with nothing to explain it.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('seeds a morning of orders that the database accepts', function (): void {
    $store = Store::factory()->create();

    $this->seed(DemoOrderSeeder::class);

    $orders = Order::query()->where('store_id', $store->id)->get();

    expect($orders)->toHaveCount(7);

    foreach ($orders as $order) {
        $lines = OrderItem::query()->where('order_id', $order->id)->get();

        expect($lines)->not->toBeEmpty()
            // The totals agree with the lines, which is what makes a receipt
            // reproducible rather than merely stored.
            ->and($lines->sum('line_total_amount'))->toBe($order->subtotal_amount)
            ->and($order->total_amount)->toBe(
                $order->subtotal_amount - $order->discount_amount
                    + $order->delivery_fee_amount + $order->tax_amount,
            )
            ->and(OrderStatusChange::query()->where('order_id', $order->id)->count())
            ->toBeGreaterThan(0);
    }
});

it('gives every seeded order a payment that matches its total', function (): void {
    Store::factory()->create();

    $this->seed(DemoOrderSeeder::class);

    foreach (Order::query()->get() as $order) {
        $payment = Payment::query()->where('order_id', $order->id)->sole();

        expect($payment->amount)->toBe($order->total_amount)
            // Money seen is money timed: the constraint requires it, and a
            // demo that could not satisfy it would be hiding a design problem.
            ->and($payment->status === PaymentStatus::Paid)
            ->toBe($payment->paid_at !== null);
    }
});

it('shows a whole morning rather than one dish repeated', function (): void {
    // A store with an empty menu still gets a varied queue: the lines are
    // snapshots, so they do not need master data to be readable.
    Store::factory()->create();

    $this->seed(DemoOrderSeeder::class);

    expect(OrderItem::query()->distinct()->count('product_name'))->toBeGreaterThan(1);
});

it('covers the states staff actually have to work with', function (): void {
    Store::factory()->create();

    $this->seed(DemoOrderSeeder::class);

    $statuses = Order::query()->pluck('status')->all();

    // An uncollected cash order that is already COMPLETED is the state the
    // whole payment separation exists for, so the demo must contain one.
    $completed = Order::query()->where('status', OrderStatus::Completed->value)->sole();
    $payment = Payment::query()->where('order_id', $completed->id)->sole();

    expect($statuses)->toContain(OrderStatus::PendingPayment)
        ->toContain(OrderStatus::Placed)
        ->toContain(OrderStatus::Preparing)
        ->toContain(OrderStatus::Ready)
        ->toContain(OrderStatus::Cancelled)
        ->and($payment->status)->toBe(PaymentStatus::Pending);
});

it('leaves a store that already has orders alone', function (): void {
    Store::factory()->create();

    $this->seed(DemoOrderSeeder::class);
    $this->seed(DemoOrderSeeder::class);

    // Re-running the seeders after adding dishes by hand must not pile up a
    // second breakfast.
    expect(Order::query()->count())->toBe(7);
});
