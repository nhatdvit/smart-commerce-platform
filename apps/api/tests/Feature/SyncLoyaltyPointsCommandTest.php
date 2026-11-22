<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Persistence\Model\User;
use App\Modules\Loyalty\Domain\Enum\EntryType;
use App\Modules\Loyalty\Infrastructure\Persistence\Model\LedgerEntryModel;
use App\Modules\Loyalty\Infrastructure\Persistence\Model\LoyaltySettingsModel;
use App\Modules\Order\Domain\Enum\OrderStatus;
use App\Modules\Order\Infrastructure\Persistence\Model\Order;
use App\Modules\Payment\Infrastructure\Persistence\Model\Payment;
use App\Modules\Store\Domain\Enum\StoreStatus;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;

/*
 * `loyalty:sync-earned-points`, the scheduled job that is the only way a PAID
 * payment ever turns into points (the migration note explains why this reads
 * `payments.status` directly instead of reacting to an event). Against real
 * PostgreSQL: the idempotency guarantee is a partial unique index, not
 * application code, and SQLite would not enforce it.
 */

/** A PAID order and payment for a phone, not yet synced. */
function paidPayment(string $storeId, string $phone, int $amount): Payment
{
    /** @var Order $order */
    $order = Order::factory()
        ->inStore($storeId)
        ->status(OrderStatus::Completed)
        ->totalling($amount)
        ->create(['contact_phone' => $phone]);

    /** @var Payment $payment */
    $payment = Payment::factory()
        ->on($order->id, $storeId, $order->order_code, $amount)
        ->paid(User::factory()->create()->id)
        ->create();

    return $payment;
}

it('awards points for a paid payment using the store policy', function (): void {
    $store = Store::factory()->create(['status' => StoreStatus::Active]);
    LoyaltySettingsModel::query()->create([
        'store_id' => $store->id,
        'points_per_currency' => 10_000,
        'loyal_tier_threshold_points' => 100,
    ]);
    $payment = paidPayment($store->id, '0909000050', 55_000);

    $this->artisan('loyalty:sync-earned-points')->assertExitCode(0);

    $entry = LedgerEntryModel::query()->where('payment_id', $payment->id)->sole();
    expect($entry->type)->toBe(EntryType::Earn)
        // Floored: 55,000 / 10,000 = 5.5, never a part point.
        ->and($entry->points)->toBe(5)
        ->and($entry->phone)->toBe('0909000050');
});

it('never awards the same payment twice, even run back to back', function (): void {
    $store = Store::factory()->create(['status' => StoreStatus::Active]);
    $payment = paidPayment($store->id, '0909000051', 100_000);

    $this->artisan('loyalty:sync-earned-points')->assertExitCode(0);
    $this->artisan('loyalty:sync-earned-points')->assertExitCode(0);

    expect(LedgerEntryModel::query()->where('payment_id', $payment->id)->count())->toBe(1);
});

it('skips a payment too small to earn a whole point, without erroring', function (): void {
    $store = Store::factory()->create(['status' => StoreStatus::Active]);
    $payment = paidPayment($store->id, '0909000052', 5_000);

    $this->artisan('loyalty:sync-earned-points')->assertExitCode(0);

    expect(LedgerEntryModel::query()->where('payment_id', $payment->id)->exists())->toBeFalse();
});

it('never awards a pending or failed payment', function (): void {
    $store = Store::factory()->create(['status' => StoreStatus::Active]);
    /** @var Order $order */
    $order = Order::factory()->inStore($store->id)->totalling(50_000)->create();
    $pending = Payment::factory()->on($order->id, $store->id, $order->order_code, 50_000)->create();

    $this->artisan('loyalty:sync-earned-points')->assertExitCode(0);

    expect(LedgerEntryModel::query()->where('payment_id', $pending->id)->exists())->toBeFalse();
});

it('credits every active store, independently', function (): void {
    $storeA = Store::factory()->create(['status' => StoreStatus::Active]);
    $storeB = Store::factory()->create(['status' => StoreStatus::Active]);
    $paymentA = paidPayment($storeA->id, '0909000053', 50_000);
    $paymentB = paidPayment($storeB->id, '0909000054', 50_000);

    $this->artisan('loyalty:sync-earned-points')->assertExitCode(0);

    expect(LedgerEntryModel::query()->where('payment_id', $paymentA->id)->exists())->toBeTrue()
        ->and(LedgerEntryModel::query()->where('payment_id', $paymentB->id)->exists())->toBeTrue();
});

it('does not touch an inactive store', function (): void {
    $store = Store::factory()->create(['status' => StoreStatus::Inactive]);
    $payment = paidPayment($store->id, '0909000055', 50_000);

    $this->artisan('loyalty:sync-earned-points')->assertExitCode(0);

    expect(LedgerEntryModel::query()->where('payment_id', $payment->id)->exists())->toBeFalse();
});
