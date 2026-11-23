<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Persistence\Model\User;
use App\Modules\Menu\Domain\Enum\MenuStatus;
use App\Modules\Menu\Infrastructure\Persistence\Model\Menu;
use App\Modules\Menu\Infrastructure\Persistence\Model\MenuItem;
use App\Modules\Order\Infrastructure\Persistence\Model\Order;
use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Modules\Payment\Infrastructure\Persistence\Model\BankAccount;
use App\Modules\Payment\Infrastructure\Persistence\Model\PaymentMethodSetting;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use Illuminate\Support\Str;

/*
 * Checkout: POST /orders, and the two reads a customer needs afterwards.
 *
 * `menuItemFor()` lives in PublicMenuTest.php; Pest loads every file in the
 * suite, so helpers are shared rather than duplicated.
 */

const CHECKOUT_HEADERS = ['Referer' => 'http://localhost', 'Origin' => 'http://localhost'];

/** An active menu with one real, checkable item - what every checkout test starts from. */
function activeMenuWithItem(
    string $storeId,
    int $price = 30_000,
    string $productName = 'Xôi gà xé',
): MenuItem {
    $menu = Menu::query()->create([
        'store_id' => $storeId,
        'name' => 'Thực đơn hằng ngày',
        'name_key' => 'thuc-don-hang-ngay',
        'status' => MenuStatus::Active->value,
    ]);

    return menuItemFor($storeId, $menu->id, 'Xôi', $productName, $price);
}

function enableCash(string $storeId): void
{
    PaymentMethodSetting::query()->create([
        'store_id' => $storeId,
        'method' => PaymentMethod::Cash->value,
        'enabled' => true,
    ]);
}

function enableBankTransfer(string $storeId): void
{
    PaymentMethodSetting::query()->create([
        'store_id' => $storeId,
        'method' => PaymentMethod::BankTransfer->value,
        'enabled' => true,
    ]);

    BankAccount::query()->create([
        'store_id' => $storeId,
        'bank_bin' => '970436',
        'bank_name' => 'Vietcombank',
        'account_number' => '0071000123456',
        'account_name' => 'HO KINH DOANH BEP NHA MINH',
        'is_default' => true,
        'is_active' => true,
    ]);
}

/** @return array{string, int} store id, item id */
function readyStore(): array
{
    $store = Store::factory()->create();
    config(['commerce.default_store_slug' => $store->slug]);
    enableCash($store->id);
    $item = activeMenuWithItem($store->id, 30_000);

    return [$store->id, $item->id];
}

function checkoutPayload(string $menuItemId, array $overrides = []): array
{
    return array_merge([
        'fulfillment_type' => 'PICKUP',
        'payment_method' => 'CASH',
        'contact' => ['name' => 'Khách lẻ', 'phone' => '0909555001'],
        'items' => [['menu_item_id' => $menuItemId, 'quantity' => 2]],
    ], $overrides);
}

function idempotencyKey(): string
{
    return 'order-'.Str::random(24);
}

it('places a cash order as PLACED, with a payment pending cash', function (): void {
    [$storeId, $itemId] = readyStore();

    $response = $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($itemId))
        ->assertCreated()
        ->assertJsonPath('order.status', 'PLACED')
        ->assertJsonPath('order.contact.name', 'Khách lẻ')
        ->assertJsonPath('order.totals.total.amount', '60000')
        ->assertJsonPath('order.items.0.quantity', 2)
        ->assertJsonPath('payment.payment.method', 'CASH')
        ->assertJsonPath('payment.payment.status', 'PENDING')
        ->assertJsonPath('payment.cash.collect_at', 'PICKUP_COUNTER');

    expect($response->json('order.order_code'))->toStartWith('SCP-')
        ->and($response->json('guest_order_token'))->toBeString()->not->toBeEmpty();
});

it('places a bank-transfer order as PENDING_PAYMENT, with a real VietQR attached', function (): void {
    $store = Store::factory()->create();
    config(['commerce.default_store_slug' => $store->slug]);
    enableBankTransfer($store->id);
    $item = activeMenuWithItem($store->id, 45_000);

    $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($item->id, ['payment_method' => 'BANK_TRANSFER']))
        ->assertCreated()
        ->assertJsonPath('order.status', 'PENDING_PAYMENT')
        ->assertJsonPath('payment.payment.method', 'BANK_TRANSFER')
        ->assertJsonPath('payment.payment.status', 'PENDING')
        ->assertJsonPath('payment.bank_transfer.bank.bin', '970436')
        ->assertJson(fn ($json) => $json->has('payment.bank_transfer.transfer_reference')
            ->has('payment.bank_transfer.qr_payload')
            ->etc());
});

it('refuses a payment method the store has not enabled', function (): void {
    $store = Store::factory()->create();
    config(['commerce.default_store_slug' => $store->slug]);
    enableCash($store->id);
    $item = activeMenuWithItem($store->id);

    $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($item->id, ['payment_method' => 'BANK_TRANSFER']))
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');
});

it('refuses an item that is no longer on the live menu', function (): void {
    [$storeId] = readyStore();

    $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload((string) Str::uuid()))
        ->assertStatus(409)
        ->assertJsonPath('code', 'MENU_UNAVAILABLE');
});

it('refuses an item that is sold out', function (): void {
    $store = Store::factory()->create();
    config(['commerce.default_store_slug' => $store->slug]);
    enableCash($store->id);
    $menu = Menu::query()->create([
        'store_id' => $store->id,
        'name' => 'Thực đơn hằng ngày',
        'name_key' => 'thuc-don-hang-ngay',
        'status' => MenuStatus::Active->value,
    ]);
    $item = menuItemFor($store->id, $menu->id, 'Xôi', 'Xôi gà xé', 30_000);
    $item->forceFill(['is_available' => false])->save();

    $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($item->id))
        ->assertStatus(409)
        ->assertJsonPath('code', 'OUT_OF_STOCK');
});

it('refuses a stale expected total - the menu price moved since the client last saw it', function (): void {
    [$storeId, $itemId] = readyStore();

    $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($itemId, ['expected_total_amount' => '1']))
        ->assertStatus(409)
        ->assertJsonPath('code', 'PRICE_CHANGED');
});

it('requires a delivery address for a delivery order', function (): void {
    [$storeId, $itemId] = readyStore();

    $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($itemId, ['fulfillment_type' => 'DELIVERY']))
        ->assertStatus(422);
});

it('requires an Idempotency-Key header', function (): void {
    [$storeId, $itemId] = readyStore();

    $this->withHeaders(CHECKOUT_HEADERS)
        ->postJson('/api/v1/orders', checkoutPayload($itemId))
        ->assertStatus(422);
});

it('replays the same order for a retried submit with the same key', function (): void {
    [$storeId, $itemId] = readyStore();
    $key = idempotencyKey();

    $first = $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/orders', checkoutPayload($itemId))
        ->assertCreated();

    $second = $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/orders', checkoutPayload($itemId))
        ->assertCreated();

    expect($second->json('order.id'))->toBe($first->json('order.id'))
        ->and(Order::query()->where('store_id', $storeId)->count())->toBe(1);
});

it('attaches a signed-in customer to their order, and issues no guest token', function (): void {
    [$storeId, $itemId] = readyStore();
    $customer = User::factory()->phoneOnly()->create(['phone' => '0909555002']);

    $response = $this->actingAs($customer, 'web')
        ->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($itemId))
        ->assertCreated();

    expect($response->json('guest_order_token'))->toBeNull();

    $order = Order::query()->where('store_id', $storeId)->sole();
    expect($order->customer_id)->toBe($customer->id);
});

it('lets the signed-in customer who placed it read their own order', function (): void {
    [$storeId, $itemId] = readyStore();
    $customer = User::factory()->phoneOnly()->create(['phone' => '0909555003']);

    $created = $this->actingAs($customer, 'web')
        ->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($itemId))
        ->assertCreated();

    $this->actingAs($customer, 'web')
        ->withHeaders(CHECKOUT_HEADERS)
        ->getJson('/api/v1/orders/'.$created->json('order.id'))
        ->assertOk()
        ->assertJsonPath('id', $created->json('order.id'));
});

it("refuses another signed-in customer reading someone else's order", function (): void {
    [$storeId, $itemId] = readyStore();
    $owner = User::factory()->phoneOnly()->create(['phone' => '0909555004']);
    $other = User::factory()->phoneOnly()->create(['phone' => '0909555005']);

    $created = $this->actingAs($owner, 'web')
        ->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($itemId))
        ->assertCreated();

    $this->actingAs($other, 'web')
        ->withHeaders(CHECKOUT_HEADERS)
        ->getJson('/api/v1/orders/'.$created->json('order.id'))
        ->assertStatus(404);
});

it('lets a guest read their own order with the issued token, and refuses a wrong one', function (): void {
    [$storeId, $itemId] = readyStore();

    $created = $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($itemId))
        ->assertCreated();

    $orderId = $created->json('order.id');
    $token = $created->json('guest_order_token');

    $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('X-Guest-Order-Token', $token)
        ->getJson('/api/v1/orders/'.$orderId)
        ->assertOk()
        ->assertJsonPath('id', $orderId);

    $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('X-Guest-Order-Token', 'not-the-real-token')
        ->getJson('/api/v1/orders/'.$orderId)
        ->assertStatus(404);

    // withHeader() persists as a default for the rest of the test, so the
    // wrong token above has to be explicitly dropped - otherwise this call
    // would silently resend it instead of testing "no credential at all".
    $this->flushHeaders();

    $this->withHeaders(CHECKOUT_HEADERS)
        ->getJson('/api/v1/orders/'.$orderId)
        ->assertStatus(401);
});

it('returns VietQR bank-transfer instructions for a bank-transfer order', function (): void {
    $store = Store::factory()->create();
    config(['commerce.default_store_slug' => $store->slug]);
    enableBankTransfer($store->id);
    $item = activeMenuWithItem($store->id, 45_000);

    $created = $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($item->id, ['payment_method' => 'BANK_TRANSFER']))
        ->assertCreated();

    $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('X-Guest-Order-Token', $created->json('guest_order_token'))
        ->getJson('/api/v1/orders/'.$created->json('order.id').'/payment')
        ->assertOk()
        ->assertJsonPath('payment.method', 'BANK_TRANSFER')
        ->assertJsonPath('bank_transfer.bank.bin', '970436')
        ->assertJsonPath('bank_transfer.bank.name', 'Vietcombank')
        ->assertJsonPath('cash', null)
        ->assertJsonPath('bank_transfer.amount.amount', '90000')
        ->assertJson(fn ($json) => $json->has('bank_transfer.transfer_reference')
            ->has('bank_transfer.qr_payload')
            ->etc());
});

it('returns cash pickup instructions for a cash order', function (): void {
    [$storeId, $itemId] = readyStore();

    $created = $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($itemId))
        ->assertCreated();

    $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('X-Guest-Order-Token', $created->json('guest_order_token'))
        ->getJson('/api/v1/orders/'.$created->json('order.id').'/payment')
        ->assertOk()
        ->assertJsonPath('payment.method', 'CASH')
        ->assertJsonPath('bank_transfer', null)
        ->assertJsonPath('cash.collect_at', 'PICKUP_COUNTER');
});

it("lists the signed-in customer's own orders, newest first", function (): void {
    [$storeId, $itemId] = readyStore();
    $customer = User::factory()->phoneOnly()->create(['phone' => '0909555010']);

    $first = $this->actingAs($customer, 'web')
        ->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($itemId))
        ->assertCreated();

    $second = $this->actingAs($customer, 'web')
        ->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($itemId))
        ->assertCreated();

    config(['sanctum.stateful' => ['localhost']]);

    $response = $this->actingAs($customer, 'web')
        ->withHeaders(CHECKOUT_HEADERS)
        ->getJson('/api/v1/customer/orders')
        ->assertOk()
        ->assertJsonCount(2, 'items');

    expect($response->json('items.0.id'))->toBe($second->json('order.id'))
        ->and($response->json('items.1.id'))->toBe($first->json('order.id'))
        ->and($response->json('items.0.order_code'))->toBe($second->json('order.order_code'))
        ->and($response->json('items.0.total.amount'))->toBe('60000')
        ->and($response->json('has_more'))->toBeFalse();
});

it("never lists another signed-in customer's orders", function (): void {
    [$storeId, $itemId] = readyStore();
    $owner = User::factory()->phoneOnly()->create(['phone' => '0909555011']);
    $other = User::factory()->phoneOnly()->create(['phone' => '0909555012']);

    $this->actingAs($owner, 'web')
        ->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($itemId))
        ->assertCreated();

    config(['sanctum.stateful' => ['localhost']]);

    $this->actingAs($other, 'web')
        ->withHeaders(CHECKOUT_HEADERS)
        ->getJson('/api/v1/customer/orders')
        ->assertOk()
        ->assertJsonCount(0, 'items');
});

it('refuses an unauthenticated caller listing orders', function (): void {
    [$storeId] = readyStore();
    config(['sanctum.stateful' => ['localhost']]);

    $this->withHeaders(CHECKOUT_HEADERS)
        ->getJson('/api/v1/customer/orders')
        ->assertUnauthorized();
});

it('lets a guest cancel their own PLACED order with the issued token', function (): void {
    [$storeId, $itemId] = readyStore();

    $created = $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($itemId))
        ->assertCreated();

    $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('X-Guest-Order-Token', $created->json('guest_order_token'))
        ->postJson('/api/v1/orders/'.$created->json('order.id').'/cancel', ['reason' => 'Đổi ý'])
        ->assertOk()
        ->assertJsonPath('status', 'CANCELLED');

    expect(Order::query()->findOrFail($created->json('order.id'))->status->value)->toBe('CANCELLED');
});

it("lets a signed-in customer cancel their own order without a reason", function (): void {
    [$storeId, $itemId] = readyStore();
    $customer = User::factory()->phoneOnly()->create(['phone' => '0909555020']);

    $created = $this->actingAs($customer, 'web')
        ->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($itemId))
        ->assertCreated();

    $this->actingAs($customer, 'web')
        ->withHeaders(CHECKOUT_HEADERS)
        ->postJson('/api/v1/orders/'.$created->json('order.id').'/cancel')
        ->assertOk()
        ->assertJsonPath('status', 'CANCELLED');
});

it('refuses to cancel an order once the kitchen has started preparing it', function (): void {
    [$storeId, $itemId] = readyStore();
    $customer = User::factory()->phoneOnly()->create(['phone' => '0909555021']);

    $created = $this->actingAs($customer, 'web')
        ->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($itemId))
        ->assertCreated();

    $order = Order::query()->findOrFail($created->json('order.id'));
    $order->forceFill(['status' => 'PREPARING'])->save();

    $this->actingAs($customer, 'web')
        ->withHeaders(CHECKOUT_HEADERS)
        ->postJson('/api/v1/orders/'.$created->json('order.id').'/cancel')
        ->assertStatus(409)
        ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    expect($order->refresh()->status->value)->toBe('PREPARING');
});

it("refuses to cancel another customer's order", function (): void {
    [$storeId, $itemId] = readyStore();
    $owner = User::factory()->phoneOnly()->create(['phone' => '0909555022']);
    $other = User::factory()->phoneOnly()->create(['phone' => '0909555023']);

    $created = $this->actingAs($owner, 'web')
        ->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($itemId))
        ->assertCreated();

    $this->actingAs($other, 'web')
        ->withHeaders(CHECKOUT_HEADERS)
        ->postJson('/api/v1/orders/'.$created->json('order.id').'/cancel')
        ->assertStatus(404);
});

it('refuses to cancel with a guest token for the wrong order', function (): void {
    [$storeId, $itemId] = readyStore();

    $created = $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($itemId))
        ->assertCreated();

    $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('X-Guest-Order-Token', 'not-the-real-token')
        ->postJson('/api/v1/orders/'.$created->json('order.id').'/cancel')
        ->assertStatus(404);
});

it('lists CASH and BANK_TRANSFER, reporting which this store has enabled', function (): void {
    $store = Store::factory()->create();
    config(['commerce.default_store_slug' => $store->slug]);
    enableCash($store->id);

    $response = $this->withHeaders(CHECKOUT_HEADERS)
        ->getJson('/api/v1/payment-methods')
        ->assertOk()
        ->assertJsonCount(2, 'items');

    $byMethod = collect($response->json('items'))->keyBy('method');

    expect($byMethod['CASH']['enabled'])->toBeTrue()
        ->and($byMethod['BANK_TRANSFER']['enabled'])->toBeFalse()
        ->and($byMethod['BANK_TRANSFER']['unavailable_reason'])->not->toBeNull();
});
