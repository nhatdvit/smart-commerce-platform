<?php

declare(strict_types=1);

use App\Modules\Order\Domain\Enum\FulfillmentType;
use App\Modules\Order\Domain\Enum\OrderStatus;
use App\Modules\Order\Infrastructure\Persistence\Model\Order;
use App\Modules\Order\Infrastructure\Persistence\Model\OrderItem;
use App\Modules\Order\Infrastructure\Persistence\Model\OrderStatusChange;
use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Modules\Payment\Domain\Enum\PaymentStatus;
use App\Modules\Payment\Infrastructure\Persistence\Model\Payment;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use App\Shared\Infrastructure\Persistence\Model\AuditLog;
use App\Shared\Infrastructure\Persistence\Model\OutboxEvent;
use Database\Seeders\RolePermissionSeeder;

/*
 * The operations queue, against real PostgreSQL.
 *
 * SQLite could not run these: the totals and the placed_at rules are CHECK
 * constraints, cross-store integrity is a composite foreign key, and the
 * concurrent transition test needs real row locking (CLAUDE.md section 12).
 *
 * `storeWithStaff()` lives in AdminProductTest.php; Pest loads every file in
 * the suite, so helpers are shared rather than duplicated.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/**
 * One order of a store, with a line and a payment, as checkout will write it.
 *
 * @param  array<string, mixed>  $attributes
 */
function orderInStore(
    string $storeId,
    OrderStatus $status = OrderStatus::Placed,
    array $attributes = [],
    string $dish = 'Xôi gà',
    int $unitPrice = 30_000,
    int $quantity = 2,
    PaymentMethod $method = PaymentMethod::Cash,
): Order {
    $total = $unitPrice * $quantity;

    /** @var Order $order */
    $order = Order::factory()
        ->inStore($storeId)
        // The status state keeps placed_at consistent with it; the database
        // refuses the combinations that make no sense.
        ->status($status)
        ->totalling($total)
        ->create($attributes);

    OrderItem::factory()
        ->on($order->id, $storeId)
        ->quantity($quantity)
        ->create(['product_name' => $dish, 'unit_price_amount' => $unitPrice]);

    $payment = Payment::factory()->on($order->id, $storeId, $order->order_code, $total);

    // A transfer carries the window a customer has to pay in; cash does not,
    // and the database refuses one that does.
    ($method === PaymentMethod::BankTransfer ? $payment->transfer() : $payment)->create();

    return $order;
}

it('lists the orders of this store only, with the open counts', function (): void {
    [$store, $manager] = storeWithStaff();
    $ours = orderInStore($store->id);
    orderInStore(Store::factory()->create()->id);

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/orders')
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.id', $ours->id)
        ->assertJsonPath('items.0.order_code', $ours->order_code)
        ->assertJsonPath('items.0.status', 'PLACED')
        ->assertJsonPath('items.0.total.amount', '60000')
        ->assertJsonPath('items.0.item_count', 1)
        ->assertJsonPath('items.0.unit_count', 2)
        // The row shows what was ordered, so staff recognise it at a glance.
        ->assertJsonPath('items.0.items.0.product_name', 'Xôi gà')
        ->assertJsonPath('items.0.payment_method', 'CASH')
        ->assertJsonPath('items.0.payment_status', 'PENDING')
        // Counts are the store's, not the page's: a queue badge has to be the
        // real number.
        ->assertJsonPath('status_counts.PLACED', 1)
        ->assertJsonPath('status_counts.READY', 0)
        ->assertJsonPath('has_more', false);
});

it('leaves finished orders out of the open counts', function (): void {
    [$store, $manager] = storeWithStaff();
    orderInStore($store->id, OrderStatus::Completed);
    orderInStore($store->id, OrderStatus::Cancelled);
    orderInStore($store->id, OrderStatus::Preparing);

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/orders')
        ->assertOk()
        ->assertJsonCount(3, 'items')
        ->assertJsonPath('status_counts.PREPARING', 1)
        // COMPLETED and CANCELLED are not open, so they are not counted at all.
        ->assertJsonMissingPath('status_counts.COMPLETED');
});

it('works the queue oldest first, and lists history newest first on request', function (): void {
    [$store, $manager] = storeWithStaff();
    $first = orderInStore($store->id);
    $second = orderInStore($store->id);

    // The oldest unserved order is the most urgent one.
    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/orders')
        ->assertOk()
        ->assertJsonPath('items.0.id', $first->id)
        ->assertJsonPath('items.1.id', $second->id);

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/orders?sort=newest_first')
        ->assertOk()
        ->assertJsonPath('items.0.id', $second->id);
});

it('pages without losing or repeating an order', function (): void {
    [$store, $manager] = storeWithStaff();
    $first = orderInStore($store->id);
    $second = orderInStore($store->id);

    $page = $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/orders?limit=1')
        ->assertOk()
        ->assertJsonPath('items.0.id', $first->id)
        ->assertJsonPath('has_more', true)
        ->json();

    $cursor = $page['next_cursor'];

    expect($cursor)->toBeString();

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/orders?limit=1&cursor='.urlencode((string) $cursor))
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.id', $second->id)
        ->assertJsonPath('has_more', false);
});

it('filters by status', function (): void {
    [$store, $manager] = storeWithStaff();
    orderInStore($store->id, OrderStatus::Placed);
    $ready = orderInStore($store->id, OrderStatus::Ready);

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/orders?status[]=READY')
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.id', $ready->id);
});

it('finds an order by code, customer or dish', function (): void {
    [$store, $manager] = storeWithStaff();
    $xoi = orderInStore($store->id, dish: 'Xôi gà', attributes: ['contact_name' => 'Lê Thảo Vy']);
    orderInStore($store->id, dish: 'Phở bò', attributes: ['contact_name' => 'Trần Quốc Huy']);

    foreach (['q=thảo', 'q=Xôi', 'q='.$xoi->order_code] as $query) {
        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/admin/orders?'.$query)
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $xoi->id);
    }

    // The code read out over the phone is matched exactly: a partial match
    // would hand staff somebody else's order.
    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/orders?order_code='.substr($xoi->order_code, 0, 8))
        ->assertOk()
        ->assertJsonCount(0, 'items');
});

it('refuses the payment status filter, which is not supported yet', function (): void {
    [, $manager] = storeWithStaff();

    // Ignoring it would show a cashier "unpaid orders" that are in fact every
    // order in the shop.
    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/orders?payment_status[]=PENDING')
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');
});

it('reads one order in full', function (): void {
    [$store, $manager] = storeWithStaff();
    $order = orderInStore($store->id, OrderStatus::Preparing, method: PaymentMethod::BankTransfer);

    OrderStatusChange::query()->create([
        'store_id' => $store->id,
        'order_id' => $order->id,
        'from_status' => OrderStatus::Placed->value,
        'to_status' => OrderStatus::Confirmed->value,
        'actor_id' => $manager->id,
        'reason' => null,
        'created_at' => now()->toImmutable(),
    ]);

    $this->actingAs($manager, 'sanctum')
        ->getJson("/api/v1/admin/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('id', $order->id)
        ->assertJsonPath('status', 'PREPARING')
        ->assertJsonPath('fulfillment_type', 'PICKUP')
        ->assertJsonPath('contact.name', 'Khách lẻ')
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.quantity', 2)
        ->assertJsonPath('items.0.unit_price.amount', '30000')
        ->assertJsonPath('items.0.line_total.amount', '60000')
        ->assertJsonPath('totals.total.amount', '60000')
        ->assertJsonPath('payment.method', 'BANK_TRANSFER')
        ->assertJsonPath('payment.status', 'PENDING')
        // Who moved the order, by name: the question that gets asked about
        // real money cannot be answered from the current status alone.
        ->assertJsonPath('status_history.0.to_status', 'CONFIRMED')
        ->assertJsonPath('status_history.0.actor_name', $manager->full_name)
        // Designed and not built, answered as null rather than invented.
        ->assertJsonPath('fulfillment_status', null);
});

it('reports another store order as not found', function (): void {
    [, $manager] = storeWithStaff();
    $theirs = orderInStore(Store::factory()->create()->id);

    $this->actingAs($manager, 'sanctum')
        ->getJson("/api/v1/admin/orders/{$theirs->id}")
        ->assertNotFound()
        ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');

    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/admin/orders/{$theirs->id}/status", ['to_status' => 'CONFIRMED'])
        ->assertNotFound();

    expect($theirs->refresh()->status)->toBe(OrderStatus::Placed);
});

it('moves an order along and records the move', function (): void {
    [$store, $manager] = storeWithStaff();
    $order = orderInStore($store->id);

    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/admin/orders/{$order->id}/status", ['to_status' => 'CONFIRMED'])
        ->assertOk()
        ->assertJsonPath('status', 'CONFIRMED')
        ->assertJsonPath('status_history.0.from_status', 'PLACED')
        ->assertJsonPath('status_history.0.to_status', 'CONFIRMED')
        ->assertJsonPath('status_history.0.actor_name', $manager->full_name);

    $change = OrderStatusChange::query()->where('order_id', $order->id)->sole();

    expect($order->refresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($change->actor_id)->toBe($manager->id)
        // The state change, its audit line and its event commit together.
        ->and(AuditLog::query()->where('action', 'order.order.transition')->count())->toBe(1)
        ->and(OutboxEvent::query()->where('event_type', 'order.status-changed')->count())->toBe(1);

    $event = OutboxEvent::query()->where('event_type', 'order.status-changed')->sole();

    expect($event->payload['from'] ?? null)->toBe('PLACED')
        ->and($event->payload['to'] ?? null)->toBe('CONFIRMED');
});

it('refuses a move the state machine does not allow', function (): void {
    [$store, $manager] = storeWithStaff();
    $order = orderInStore($store->id);

    // Straight from PLACED to READY would skip confirming and preparing: an
    // invalid jump is a conflict, never a quiet no-op.
    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/admin/orders/{$order->id}/status", ['to_status' => 'READY'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'INVALID_STATE_TRANSITION')
        ->assertJsonPath('details.0.field', 'to_status');

    expect($order->refresh()->status)->toBe(OrderStatus::Placed);
});

it('refuses to move a finished order', function (): void {
    [$store, $manager] = storeWithStaff();
    $order = orderInStore($store->id, OrderStatus::Completed);

    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/admin/orders/{$order->id}/status", ['to_status' => 'CANCELLED'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
});

it('refuses to re-send the status an order is already in', function (): void {
    [$store, $manager] = storeWithStaff();
    $order = orderInStore($store->id, OrderStatus::Ready);

    // Two staff tapping at once: the second one must be told their screen is
    // stale rather than silently succeeding.
    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/admin/orders/{$order->id}/status", ['to_status' => 'READY'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
});

it('will not place an order awaiting a transfer by hand', function (): void {
    [$store, $manager] = storeWithStaff();
    $order = orderInStore(
        $store->id,
        OrderStatus::PendingPayment,
        method: PaymentMethod::BankTransfer,
    );

    // That move belongs to payment confirmation, which checks the real bank
    // credit. Here it would be a one-click way to serve an unpaid order.
    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/admin/orders/{$order->id}/status", ['to_status' => 'PLACED'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->placed_at)->toBeNull();
});

it('lets an order awaiting a transfer be cancelled', function (): void {
    [$store, $manager] = storeWithStaff();
    $order = orderInStore(
        $store->id,
        OrderStatus::PendingPayment,
        method: PaymentMethod::BankTransfer,
    );

    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/admin/orders/{$order->id}/status", [
            'to_status' => 'CANCELLED',
            'reason' => 'Khách không chuyển khoản',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'CANCELLED')
        // Never placed, so it keeps no placed time.
        ->assertJsonPath('placed_at', null);
});

it('requires a reason to cancel', function (): void {
    [$store, $manager] = storeWithStaff();
    $order = orderInStore($store->id);

    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/admin/orders/{$order->id}/status", ['to_status' => 'CANCELLED'])
        ->assertStatus(422)
        ->assertJsonPath('details.0.field', 'reason');

    expect($order->refresh()->status)->toBe(OrderStatus::Placed);

    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/admin/orders/{$order->id}/status", [
            'to_status' => 'CANCELLED',
            'reason' => 'Khách đổi ý',
        ])
        ->assertOk()
        ->assertJsonPath('status_history.0.reason', 'Khách đổi ý');
});

it('completing an order does not collect the money', function (): void {
    [$store, $manager] = storeWithStaff();
    $order = orderInStore($store->id, OrderStatus::Ready);

    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/admin/orders/{$order->id}/status", ['to_status' => 'COMPLETED'])
        ->assertOk()
        ->assertJsonPath('status', 'COMPLETED')
        // The two state machines are separate: an uncollected cash order is a
        // real state a shop needs to see (CLAUDE.md section 6).
        ->assertJsonPath('payment.status', 'PENDING')
        ->assertJsonPath('payment.paid_at', null);

    $payment = Payment::query()->where('order_id', $order->id)->sole();

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->paid_at)->toBeNull();
});

it('lets kitchen staff work the preparation steps without a manager', function (): void {
    [$store, $kitchen] = storeWithStaff('KITCHEN_STAFF');
    $order = orderInStore($store->id, OrderStatus::Confirmed);

    $this->actingAs($kitchen, 'sanctum')
        ->postJson("/api/v1/admin/orders/{$order->id}/status", ['to_status' => 'PREPARING'])
        ->assertOk()
        ->assertJsonPath('status', 'PREPARING');

    $this->actingAs($kitchen, 'sanctum')
        ->postJson("/api/v1/admin/orders/{$order->id}/status", ['to_status' => 'READY'])
        ->assertOk()
        ->assertJsonPath('status', 'READY');
});

it('refuses to let kitchen staff complete or cancel an order', function (): void {
    [$store, $kitchen] = storeWithStaff('KITCHEN_STAFF');
    $ready = orderInStore($store->id, OrderStatus::Ready);
    $placed = orderInStore($store->id);

    // Handing over an order and cancelling one touch the money and the
    // customer; KITCHEN_STAFF holds no order.transition grant.
    $this->actingAs($kitchen, 'sanctum')
        ->postJson("/api/v1/admin/orders/{$ready->id}/status", ['to_status' => 'COMPLETED'])
        ->assertForbidden();

    $this->actingAs($kitchen, 'sanctum')
        ->postJson("/api/v1/admin/orders/{$placed->id}/status", [
            'to_status' => 'CANCELLED',
            'reason' => 'Thử',
        ])
        ->assertForbidden();

    expect($ready->refresh()->status)->toBe(OrderStatus::Ready)
        ->and($placed->refresh()->status)->toBe(OrderStatus::Placed);
});

it('lets a cashier confirm and complete orders', function (): void {
    [$store, $cashier] = storeWithStaff('CASHIER');
    $order = orderInStore($store->id, OrderStatus::Ready);

    $this->actingAs($cashier, 'sanctum')
        ->postJson("/api/v1/admin/orders/{$order->id}/status", ['to_status' => 'COMPLETED'])
        ->assertOk()
        ->assertJsonPath('status', 'COMPLETED');
});

it('rejects an unknown status', function (): void {
    [$store, $manager] = storeWithStaff();
    $order = orderInStore($store->id);

    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/admin/orders/{$order->id}/status", ['to_status' => 'SHIPPED'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');
});

it('keeps a delivery order readable with its address snapshot', function (): void {
    [$store, $manager] = storeWithStaff();
    $order = orderInStore($store->id, attributes: [
        'fulfillment_type' => FulfillmentType::Delivery->value,
        'delivery_address_line' => '45 Lê Lợi, Quận 1',
    ]);

    $this->actingAs($manager, 'sanctum')
        ->getJson("/api/v1/admin/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('fulfillment_type', 'DELIVERY')
        ->assertJsonPath('delivery_address_line', '45 Lê Lợi, Quận 1');
});

it('refuses an unauthenticated caller', function (): void {
    [$store] = storeWithStaff();
    $order = orderInStore($store->id);

    $this->getJson('/api/v1/admin/orders')->assertUnauthorized();
    $this->getJson("/api/v1/admin/orders/{$order->id}")->assertUnauthorized();
    $this->postJson("/api/v1/admin/orders/{$order->id}/status", ['to_status' => 'CONFIRMED'])
        ->assertUnauthorized();
});
