<?php

declare(strict_types=1);

use App\Modules\Order\Domain\Enum\OrderStatus;
use App\Modules\Order\Infrastructure\Persistence\Model\OrderStatusChange;
use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Modules\Payment\Infrastructure\Persistence\Model\Payment;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Outbox\DispatchPendingOutboxEvents;
use App\Shared\Infrastructure\Outbox\OutboxEventPublished;
use App\Shared\Infrastructure\Persistence\Model\OutboxEvent;
use Database\Seeders\RolePermissionSeeder;

/*
 * The one place `payment.confirmed` actually does something: it is what
 * finally places an order a customer paid for by bank transfer, since
 * TransitionOrder's own HTTP-facing guard refuses to make that exact move by
 * hand (see AdminOrderTest's "will not place an order awaiting a transfer by
 * hand"). Run against the real queue-publish pipeline, not a mock of it - the
 * thing worth proving is that AdvanceOrderOnPaymentConfirmed is actually
 * registered and wired to TransitionOrder, which a unit test of the listener
 * in isolation could pass while the registration itself was missing.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('places an order once its bank transfer is confirmed', function (): void {
    [$store, $manager] = storeWithStaff();
    $order = orderInStore($store->id, OrderStatus::PendingPayment, method: PaymentMethod::BankTransfer);
    $payment = Payment::query()->where('order_id', $order->id)->sole();

    $this->actingAs($manager, 'sanctum')
        ->postJson(
            "/api/v1/admin/payments/{$payment->id}/confirm",
            ['amount' => (string) $payment->amount],
            ['Idempotency-Key' => 'confirm-advance-1'],
        )
        ->assertOk();

    // Confirming only records the fact and queues the event; nothing about
    // the order moves until the outbox actually publishes it.
    expect($order->refresh()->status)->toBe(OrderStatus::PendingPayment);

    (new DispatchPendingOutboxEvents)();

    expect($order->refresh()->status)->toBe(OrderStatus::Placed)
        ->and($order->placed_at)->not->toBeNull()
        ->and(
            OrderStatusChange::query()
                ->where('order_id', $order->id)
                ->where('to_status', 'PLACED')
                ->exists(),
        )->toBeTrue();
});

it('leaves a cash order alone: it is already past PENDING_PAYMENT', function (): void {
    [$store, $manager] = storeWithStaff();
    $order = orderInStore($store->id, OrderStatus::Placed, method: PaymentMethod::Cash);
    $payment = Payment::query()->where('order_id', $order->id)->sole();

    $this->actingAs($manager, 'sanctum')
        ->postJson(
            "/api/v1/admin/payments/{$payment->id}/confirm",
            ['amount' => (string) $payment->amount],
            ['Idempotency-Key' => 'confirm-advance-2'],
        )
        ->assertOk();

    // The listener fires for every payment.confirmed, cash included; it has
    // to be a safe no-op rather than an attempted (and refused) PLACED->PLACED
    // move that would fail the whole outbox-publish job.
    (new DispatchPendingOutboxEvents)();

    expect($order->refresh()->status)->toBe(OrderStatus::Placed);
});

it('does not re-place an order a second delivery of the same event finds already placed', function (): void {
    [$store, $manager] = storeWithStaff();
    $order = orderInStore($store->id, OrderStatus::PendingPayment, method: PaymentMethod::BankTransfer);
    $payment = Payment::query()->where('order_id', $order->id)->sole();

    $this->actingAs($manager, 'sanctum')
        ->postJson(
            "/api/v1/admin/payments/{$payment->id}/confirm",
            ['amount' => (string) $payment->amount],
            ['Idempotency-Key' => 'confirm-advance-3'],
        )
        ->assertOk();

    (new DispatchPendingOutboxEvents)();
    expect($order->refresh()->status)->toBe(OrderStatus::Placed);

    $placedTransitions = fn (): int => OrderStatusChange::query()
        ->where('order_id', $order->id)
        ->where('to_status', 'PLACED')
        ->count();
    expect($placedTransitions())->toBe(1);

    // A second, independent delivery of the same event - exactly what
    // OutboxEventPublished's own docblock says to expect ("at-least-once"),
    // simulated here the way it would really happen: a queue retry re-running
    // PublishOutboxEvent::handle() for the same already-PUBLISHED row.
    $recorded = OutboxEvent::query()->where('event_type', 'payment.confirmed')->sole();
    event(new OutboxEventPublished(
        $recorded->id,
        new OutboxMessage('payment', $payment->id, 'payment.confirmed', $recorded->payload),
    ));

    expect($order->refresh()->status)->toBe(OrderStatus::Placed)
        ->and($placedTransitions())->toBe(1);
});
