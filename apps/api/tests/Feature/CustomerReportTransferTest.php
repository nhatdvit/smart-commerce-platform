<?php

declare(strict_types=1);

use App\Modules\Payment\Infrastructure\Persistence\Model\Payment;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;

/*
 * "I have transferred" (CLAUDE.md section 6, decision dated 2026-11-24): a
 * customer notification, never proof. `pngBytes()` and `uploadImage()` live
 * in ProductImageTest.php; `CHECKOUT_HEADERS`, `enableBankTransfer()`,
 * `enableCash()`, `activeMenuWithItem()`, `checkoutPayload()` and
 * `idempotencyKey()` live in CheckoutTest.php; `storeWithStaff()` lives in
 * AdminProductTest.php - Pest loads every file in the suite, so helpers are
 * shared rather than duplicated.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
});

it('moves payment to PROCESSING as a notification, never PAID', function (): void {
    [$store] = storeWithStaff();
    enableBankTransfer($store->id);
    $item = activeMenuWithItem($store->id, 30_000);

    $created = $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($item->id, ['payment_method' => 'BANK_TRANSFER']))
        ->assertCreated();
    $orderId = (string) $created->json('order.id');

    $this->withHeader('X-Guest-Order-Token', (string) $created->json('guest_order_token'))
        ->postJson("/api/v1/orders/{$orderId}/payment/report-transfer")
        ->assertOk()
        ->assertJsonPath('payment.status', 'PROCESSING')
        ->assertJson(fn ($json) => $json->has('payment.customer_reported_at')->etc());

    $payment = Payment::query()->where('order_id', $orderId)->sole();
    expect($payment->status->value)->toBe('PROCESSING')
        ->and($payment->customer_reported_at)->not->toBeNull()
        ->and($payment->customer_receipt_path)->toBeNull();
});

it('accepts a receipt photo and makes it visible to staff, never to confirm itself', function (): void {
    [$store, $manager] = storeWithStaff();
    enableBankTransfer($store->id);
    $item = activeMenuWithItem($store->id, 30_000);

    $created = $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($item->id, ['payment_method' => 'BANK_TRANSFER']))
        ->assertCreated();
    $orderId = (string) $created->json('order.id');

    $this->withHeader('X-Guest-Order-Token', (string) $created->json('guest_order_token'))
        ->post("/api/v1/orders/{$orderId}/payment/report-transfer", [
            'receipt' => uploadImage(pngBytes()),
        ])
        ->assertOk();

    $payment = Payment::query()->where('order_id', $orderId)->sole();
    expect($payment->customer_receipt_path)->not->toBeNull();

    $this->actingAs($manager, 'sanctum')
        ->getJson("/api/v1/admin/payments/{$payment->id}")
        ->assertOk()
        ->assertJsonPath('has_customer_receipt', true)
        ->assertJsonPath('status', 'PROCESSING');

    $this->actingAs($manager, 'sanctum')
        ->get("/api/v1/admin/payments/{$payment->id}/receipt")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

it('still lets staff confirm a payment the customer already reported', function (): void {
    [$store, $manager] = storeWithStaff();
    enableBankTransfer($store->id);
    $item = activeMenuWithItem($store->id, 30_000);

    $created = $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($item->id, ['payment_method' => 'BANK_TRANSFER']))
        ->assertCreated();
    $orderId = (string) $created->json('order.id');

    $this->withHeader('X-Guest-Order-Token', (string) $created->json('guest_order_token'))
        ->postJson("/api/v1/orders/{$orderId}/payment/report-transfer")
        ->assertOk()
        ->assertJsonPath('payment.status', 'PROCESSING');

    $payment = Payment::query()->where('order_id', $orderId)->sole();

    $this->actingAs($manager, 'sanctum')
        ->postJson(
            "/api/v1/admin/payments/{$payment->id}/confirm",
            ['amount' => (string) $payment->amount],
            ['Idempotency-Key' => 'confirm-after-report-1'],
        )
        ->assertOk()
        ->assertJsonPath('status', 'PAID');
});

it('refuses to report a cash payment, which has nothing to transfer', function (): void {
    [$store] = storeWithStaff();
    enableCash($store->id);
    $item = activeMenuWithItem($store->id, 30_000);

    $created = $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($item->id))
        ->assertCreated();

    $this->withHeader('X-Guest-Order-Token', (string) $created->json('guest_order_token'))
        ->postJson('/api/v1/orders/'.$created->json('order.id').'/payment/report-transfer')
        ->assertStatus(422);
});

it('refuses a guest token that does not match this order', function (): void {
    [$store] = storeWithStaff();
    enableBankTransfer($store->id);
    $item = activeMenuWithItem($store->id, 30_000);

    $created = $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($item->id, ['payment_method' => 'BANK_TRANSFER']))
        ->assertCreated();

    $this->withHeader('X-Guest-Order-Token', 'not-the-real-token')
        ->postJson('/api/v1/orders/'.$created->json('order.id').'/payment/report-transfer')
        ->assertNotFound();
});

it('refuses staff of another store reading a payment receipt, as not found', function (): void {
    [$store] = storeWithStaff();
    enableBankTransfer($store->id);
    $item = activeMenuWithItem($store->id, 30_000);

    $created = $this->withHeaders(CHECKOUT_HEADERS)
        ->withHeader('Idempotency-Key', idempotencyKey())
        ->postJson('/api/v1/orders', checkoutPayload($item->id, ['payment_method' => 'BANK_TRANSFER']))
        ->assertCreated();
    $orderId = (string) $created->json('order.id');

    $this->withHeader('X-Guest-Order-Token', (string) $created->json('guest_order_token'))
        ->post("/api/v1/orders/{$orderId}/payment/report-transfer", [
            'receipt' => uploadImage(pngBytes()),
        ])
        ->assertOk();

    $payment = Payment::query()->where('order_id', $orderId)->sole();

    // A fresh store and manager - storeWithStaff() repoints the single-store
    // default to it, the same way a real second deployment would differ.
    [, $theirManager] = storeWithStaff();

    $this->actingAs($theirManager, 'sanctum')
        ->get("/api/v1/admin/payments/{$payment->id}/receipt")
        ->assertNotFound();
});
