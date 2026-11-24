<?php

declare(strict_types=1);

use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Modules\Payment\Domain\Enum\PaymentStatus;
use App\Modules\Payment\Infrastructure\Persistence\Model\BankAccount;
use App\Modules\Payment\Infrastructure\Persistence\Model\Payment;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use App\Shared\Infrastructure\Persistence\Model\AuditLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

/*
 * The reconciliation queue, against real PostgreSQL.
 *
 * SQLite could not run these: the rule that a settled payment refuses a
 * second confirmation and the rule that an amount mismatch is refused unless
 * explicitly accepted are both CHECK constraints as well as domain rules, and
 * the duplicate-confirmation scenario needs the same row-lock behaviour the
 * concurrent order-transition tests do (CLAUDE.md section 12).
 *
 * `storeWithStaff()` lives in AdminProductTest.php, `orderInStore()` in
 * AdminOrderTest.php; Pest loads every file in the suite, so helpers are
 * shared rather than duplicated.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('lists this store payments only, newest first', function (): void {
    [$store, $manager] = storeWithStaff();
    $ours = orderInStore($store->id);
    orderInStore(Store::factory()->create()->id);

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/payments')
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.order_id', $ours->id)
        ->assertJsonPath('items.0.status', 'PENDING')
        ->assertJsonPath('items.0.amount.amount', '60000')
        ->assertJsonPath('items.0.needs_attention', true)
        ->assertJsonPath('has_more', false);
});

it('shows a settled payment with a discrepancy as still needing attention, on the list and the detail read alike', function (): void {
    // A real bug this test guards against: the list projection must agree
    // with the detail read about needs_attention, including for a payment
    // that is PAID but was flagged for a mismatch - there is no "resolved" to
    // fall back to (CLAUDE.md section 6).
    [$store, $manager] = storeWithStaff();
    $order = orderInStore($store->id, method: PaymentMethod::BankTransfer);
    $payment = Payment::query()->where('order_id', $order->id)->sole();
    $payment->fill([
        'status' => PaymentStatus::Paid,
        'confirmed_by' => $manager->id,
        'confirmed_at' => now(),
        'confirmed_amount' => $payment->amount,
        'paid_at' => now(),
    ])->save();

    DB::table('payment_discrepancies')->insert([
        'store_id' => $store->id,
        'payment_id' => $payment->id,
        'type' => 'OVERPAID',
        'expected_amount' => $payment->amount,
        'observed_amount' => $payment->amount + 10_000,
        'recorded_by' => $manager->id,
        'recorded_at' => now(),
    ]);

    $list = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/admin/payments');
    $list->assertOk()->assertJsonPath('items.0.needs_attention', true);

    $this->actingAs($manager, 'sanctum')
        ->getJson("/api/v1/admin/payments/{$payment->id}")
        ->assertOk()
        ->assertJsonPath('needs_attention', true)
        ->assertJsonPath('discrepancies.0.type', 'OVERPAID');
});

it('filters the queue by status, method, needs_attention and an exact transfer reference', function (): void {
    [$store, $manager] = storeWithStaff();
    $cash = orderInStore($store->id, method: PaymentMethod::Cash);
    $transfer = orderInStore($store->id, method: PaymentMethod::BankTransfer);
    Payment::query()->where('order_id', $transfer->id)
        ->update(['transfer_reference' => 'SCPFILTERME1']);

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/payments?'.http_build_query(['method' => 'BANK_TRANSFER']))
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.order_id', $transfer->id);

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/payments?'.http_build_query(['transfer_reference' => 'SCPFILTERME1']))
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.order_id', $transfer->id);

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/payments?'.http_build_query(['status' => ['PENDING']]))
        ->assertOk()
        ->assertJsonCount(2, 'items');

    // "true"/"false" is what a browser's own URLSearchParams produces for a
    // boolean - not "1"/"0" - and Laravel's own `boolean` rule only accepts
    // the latter. Both forms have to work, or every real client 422s.
    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/payments?needs_attention=true')
        ->assertOk()
        ->assertJsonCount(2, 'items');

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/payments?needs_attention=1')
        ->assertOk()
        ->assertJsonCount(2, 'items');

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/payments?needs_attention=false')
        ->assertOk()
        ->assertJsonCount(2, 'items');

    expect($cash->id)->not->toBe($transfer->id);
});

it('reports a payment of another store as not found, not as forbidden', function (): void {
    [, $manager] = storeWithStaff();
    $theirs = orderInStore(Store::factory()->create()->id);
    $theirPayment = Payment::query()->where('order_id', $theirs->id)->sole();

    $this->actingAs($manager, 'sanctum')
        ->getJson("/api/v1/admin/payments/{$theirPayment->id}")
        ->assertNotFound()
        ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
});

it('confirms a bank transfer and names who confirmed it, once, with an idempotency key', function (): void {
    [$store, $manager] = storeWithStaff();
    $order = orderInStore($store->id, method: PaymentMethod::BankTransfer);
    $payment = Payment::query()->where('order_id', $order->id)->sole();

    $response = $this->actingAs($manager, 'sanctum')
        ->postJson(
            "/api/v1/admin/payments/{$payment->id}/confirm",
            ['amount' => (string) $payment->amount, 'bank_transaction_reference' => 'FT26112112345'],
            ['Idempotency-Key' => 'confirm-key1'],
        )
        ->assertOk()
        ->assertJsonPath('status', 'PAID')
        ->assertJsonPath('confirmed_by', $manager->full_name)
        ->assertJsonPath('bank_transaction_reference', 'FT26112112345')
        ->assertJsonPath('needs_attention', false);

    expect($response->json('confirmed_at'))->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'payment.confirm')->sole()->actor_id)->toBe($manager->id);
});

it('requires an idempotency key to confirm', function (): void {
    [$store, $manager] = storeWithStaff();
    $order = orderInStore($store->id, method: PaymentMethod::BankTransfer);
    $payment = Payment::query()->where('order_id', $order->id)->sole();

    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/admin/payments/{$payment->id}/confirm", ['amount' => (string) $payment->amount])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('refuses to confirm a payment that is already paid', function (): void {
    // The named critical scenario: duplicate manual payment confirmation
    // (CLAUDE.md section 12) must be refused by the domain, not merely
    // deduplicated by the idempotency key - a different staff member, a
    // second tab, is a genuinely new request.
    [$store, $manager] = storeWithStaff();
    $order = orderInStore($store->id, method: PaymentMethod::BankTransfer);
    $payment = Payment::query()->where('order_id', $order->id)->sole();
    $payment->fill([
        'status' => PaymentStatus::Paid,
        'confirmed_by' => $manager->id,
        'confirmed_at' => now(),
        'confirmed_amount' => $payment->amount,
        'paid_at' => now(),
    ])->save();

    $this->actingAs($manager, 'sanctum')
        ->postJson(
            "/api/v1/admin/payments/{$payment->id}/confirm",
            ['amount' => (string) $payment->amount],
            ['Idempotency-Key' => 'confirm-key2'],
        )
        ->assertStatus(409)
        ->assertJsonPath('code', 'PAYMENT_ALREADY_CONFIRMED');
});

it('refuses an amount mismatch unless accepted, then records it as a discrepancy once it is', function (): void {
    // The other named critical scenario: an incorrect or duplicate bank
    // transfer amount is never auto-resolved (CLAUDE.md section 6).
    [$store, $manager] = storeWithStaff();
    $order = orderInStore($store->id, method: PaymentMethod::BankTransfer);
    $payment = Payment::query()->where('order_id', $order->id)->sole();
    $short = $payment->amount - 10_000;

    $this->actingAs($manager, 'sanctum')
        ->postJson(
            "/api/v1/admin/payments/{$payment->id}/confirm",
            ['amount' => (string) $short],
            ['Idempotency-Key' => 'confirm-key3'],
        )
        ->assertStatus(409)
        ->assertJsonPath('code', 'PAYMENT_AMOUNT_MISMATCH');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);

    $this->actingAs($manager, 'sanctum')
        ->postJson(
            "/api/v1/admin/payments/{$payment->id}/confirm",
            ['amount' => (string) $short, 'accept_discrepancy' => true],
            ['Idempotency-Key' => 'confirm-key4'],
        )
        ->assertOk()
        ->assertJsonPath('status', 'PAID')
        ->assertJsonPath('confirmed_amount.amount', (string) $short)
        ->assertJsonPath('discrepancies.0.type', 'UNDERPAID')
        ->assertJsonPath('needs_attention', true);
});

it('requires a disposition for an overpayment, then records it on the discrepancy', function (): void {
    // CLAUDE.md section 6, decision dated 2026-11-24: an overpayment is never
    // left as a bare number - accepting the mismatch is not enough, the
    // operator must also say what becomes of the excess in the same call.
    [$store, $manager] = storeWithStaff();
    $order = orderInStore($store->id, method: PaymentMethod::BankTransfer);
    $payment = Payment::query()->where('order_id', $order->id)->sole();
    $extra = $payment->amount + 2_000;

    $this->actingAs($manager, 'sanctum')
        ->postJson(
            "/api/v1/admin/payments/{$payment->id}/confirm",
            ['amount' => (string) $extra, 'accept_discrepancy' => true],
            ['Idempotency-Key' => 'confirm-overpaid-1'],
        )
        ->assertStatus(409)
        ->assertJsonPath('code', 'PAYMENT_AMOUNT_MISMATCH');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Pending);

    $this->actingAs($manager, 'sanctum')
        ->postJson(
            "/api/v1/admin/payments/{$payment->id}/confirm",
            [
                'amount' => (string) $extra,
                'accept_discrepancy' => true,
                'overpayment_disposition' => 'KEEP',
            ],
            ['Idempotency-Key' => 'confirm-overpaid-2'],
        )
        ->assertOk()
        ->assertJsonPath('status', 'PAID')
        ->assertJsonPath('discrepancies.0.type', 'OVERPAID')
        ->assertJsonPath('discrepancies.0.disposition', 'KEEP');
});

it('flags an exception without confirming or failing the payment, and audits it', function (): void {
    [$store, $manager] = storeWithStaff();
    $order = orderInStore($store->id, method: PaymentMethod::BankTransfer);
    $payment = Payment::query()->where('order_id', $order->id)->sole();

    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/admin/payments/{$payment->id}/flag", [
            'type' => 'UNKNOWN_REFERENCE',
            'note' => 'Không khớp đơn nào',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'PENDING')
        ->assertJsonPath('discrepancies.0.type', 'UNKNOWN_REFERENCE')
        ->assertJsonPath('discrepancies.0.recorded_by', $manager->full_name)
        ->assertJsonPath('needs_attention', true);

    $entry = AuditLog::query()->where('action', 'payment.flag')->sole();
    expect($entry->actor_id)->toBe($manager->id)
        ->and($entry->changes['type'] ?? null)->toBe('UNKNOWN_REFERENCE');
});

it('regenerates the QR for a payment still awaiting its transfer', function (): void {
    [$store, $manager] = storeWithStaff();
    BankAccount::factory()->inStore($store->id)->create();
    $order = orderInStore($store->id, method: PaymentMethod::BankTransfer);
    $payment = Payment::query()->where('order_id', $order->id)->sole();
    $payment->update(['transfer_reference' => 'SCPORIGINAL1', 'qr_payload' => '00020101STALE']);

    $response = $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/admin/payments/{$payment->id}/regenerate-qr", [])
        ->assertOk()
        ->assertJsonPath('status', 'PENDING');

    $refreshed = $payment->refresh();
    expect($refreshed->transfer_reference)->not->toBe('SCPORIGINAL1')
        ->and($refreshed->qr_payload)->not->toBe('00020101STALE')
        ->and($response->json('transfer_reference'))->toBe($refreshed->transfer_reference)
        ->and($response->json('qr_payload'))->toBe($refreshed->qr_payload)
        ->and($refreshed->expires_at)->not->toBeNull()
        ->and($refreshed->expires_at->isFuture())->toBeTrue();

    $entry = AuditLog::query()->where('action', 'payment.transfer-instructions.regenerate')->sole();
    expect($entry->actor_id)->toBe($manager->id)
        ->and($entry->changes['previous_transfer_reference'] ?? null)->toBe('SCPORIGINAL1')
        ->and($entry->changes['transfer_reference'] ?? null)->toBe($refreshed->transfer_reference);
});

it('refuses to regenerate a QR for a cash payment', function (): void {
    [$store, $manager] = storeWithStaff();
    $order = orderInStore($store->id, method: PaymentMethod::Cash);
    $payment = Payment::query()->where('order_id', $order->id)->sole();

    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/admin/payments/{$payment->id}/regenerate-qr", [])
        ->assertStatus(409)
        ->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
});

it('refuses to regenerate a QR for a payment already settled', function (): void {
    [$store, $manager] = storeWithStaff();
    BankAccount::factory()->inStore($store->id)->create();
    $order = orderInStore($store->id, method: PaymentMethod::BankTransfer);
    $payment = Payment::query()->where('order_id', $order->id)->sole();
    $payment->fill([
        'status' => PaymentStatus::Paid,
        'confirmed_by' => $manager->id,
        'confirmed_at' => now(),
        'confirmed_amount' => $payment->amount,
        'paid_at' => now(),
    ])->save();

    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/admin/payments/{$payment->id}/regenerate-qr", [])
        ->assertStatus(409)
        ->assertJsonPath('code', 'PAYMENT_ALREADY_CONFIRMED');
});

it('refuses kitchen staff regenerating a QR, same as confirming', function (): void {
    [$store, $kitchen] = storeWithStaff('KITCHEN_STAFF');
    $order = orderInStore($store->id, method: PaymentMethod::BankTransfer);
    $payment = Payment::query()->where('order_id', $order->id)->sole();

    $this->actingAs($kitchen, 'sanctum')
        ->postJson("/api/v1/admin/payments/{$payment->id}/regenerate-qr", [])
        ->assertForbidden()
        ->assertJsonPath('code', 'STORE_SCOPE_DENIED');
});

it('lets a cashier read and confirm payments', function (): void {
    [$store, $cashier] = storeWithStaff('CASHIER');
    $order = orderInStore($store->id, method: PaymentMethod::Cash);
    $payment = Payment::query()->where('order_id', $order->id)->sole();

    $this->actingAs($cashier, 'sanctum')
        ->getJson('/api/v1/admin/payments')
        ->assertOk();

    $this->actingAs($cashier, 'sanctum')
        ->postJson(
            "/api/v1/admin/payments/{$payment->id}/confirm",
            ['amount' => (string) $payment->amount],
            ['Idempotency-Key' => 'confirm-key5'],
        )
        ->assertOk()
        ->assertJsonPath('status', 'PAID');
});

it('refuses kitchen staff entirely: no payment or pricing access', function (): void {
    [$store, $kitchen] = storeWithStaff('KITCHEN_STAFF');
    orderInStore($store->id);

    $this->actingAs($kitchen, 'sanctum')
        ->getJson('/api/v1/admin/payments')
        ->assertForbidden()
        ->assertJsonPath('code', 'STORE_SCOPE_DENIED');
});

it('refuses an unauthenticated caller', function (): void {
    $this->getJson('/api/v1/admin/payments')->assertUnauthorized();
    $this->postJson('/api/v1/admin/payments/01930000-0000-7000-8000-000000000001/confirm', [])
        ->assertUnauthorized();
    $this->postJson('/api/v1/admin/payments/01930000-0000-7000-8000-000000000001/regenerate-qr', [])
        ->assertUnauthorized();
});
