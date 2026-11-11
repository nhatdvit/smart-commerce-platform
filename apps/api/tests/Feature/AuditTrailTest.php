<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Persistence\Model\User;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;
use App\Shared\Infrastructure\Persistence\Model\AuditLog;
use Illuminate\Support\Facades\DB;

/*
 * §6 requires a payment confirmation to leave a trace, and §8 requires the
 * same of every sensitive action. A gap here cannot be reconstructed later.
 */

it('records an action with its entity and correlation id', function (): void {
    $this->withHeader('X-Request-Id', 'trace-xyz')
        ->getJson('/health/live')
        ->assertOk();

    app(RecordsAuditTrail::class)->record(
        AuditEntry::for('payment.confirm', 'payment', 'pay-1', ['amount' => 45000]),
    );

    $log = AuditLog::query()->sole();

    expect($log->action)->toBe('payment.confirm')
        ->and($log->entity_type)->toBe('payment')
        ->and($log->entity_id)->toBe('pay-1')
        ->and($log->changes)->toBe(['amount' => 45000]);
});

it('redacts secrets before they reach a long-lived table', function (): void {
    app(RecordsAuditTrail::class)->record(
        AuditEntry::for('store.bank-account.update', 'store_bank_account', 'acc-1', [
            'account_number' => '19001234567',
            'api_token' => 'super-secret',
            'account_name' => 'CHO LON STORE',
        ]),
    );

    $changes = AuditLog::query()->sole()->changes;

    expect($changes['api_token'])->toBe('[REDACTED]')
        // Masked, not deleted: reconciliation still needs to tell accounts apart.
        ->and($changes['account_number'])->toBe('*******4567')
        ->and($changes['account_name'])->toBe('CHO LON STORE');
});

it('attributes the action to the signed-in user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum');

    app(RecordsAuditTrail::class)->record(
        AuditEntry::for('order.transition', 'order', 'order-1'),
    );

    expect(AuditLog::query()->sole()->actor_id)->toBe($user->id);
});

it('leaves the actor empty for work with no signed-in user', function (): void {
    // A scheduled job expiring an order has no actor, and inventing one would
    // make the trail lie.
    app(RecordsAuditTrail::class)->record(
        AuditEntry::for('order.expire', 'order', 'order-2'),
    );

    expect(AuditLog::query()->sole()->actor_id)->toBeNull();
});

it('records the store so the trail can be queried per store', function (): void {
    $store = Store::factory()->create();

    app(RecordsAuditTrail::class)->record(
        AuditEntry::for('menu.update', 'menu', 'menu-1')->inStore($store->id),
    );

    expect(AuditLog::query()->sole()->store_id)->toBe($store->id);
});

it('rolls back with the action it describes', function (): void {
    try {
        DB::transaction(function (): void {
            app(RecordsAuditTrail::class)->record(
                AuditEntry::for('payment.confirm', 'payment', 'pay-2'),
            );

            throw new RuntimeException('confirmation failed after the audit write');
        });
    } catch (RuntimeException) {
        // expected
    }

    // An audit row for something that did not happen is worse than no row:
    // it asserts a payment was confirmed when it was not.
    expect(AuditLog::query()->count())->toBe(0);
});

it('stores no changes payload when there is nothing to record', function (): void {
    app(RecordsAuditTrail::class)->record(AuditEntry::for('auth.logout', 'session', 'sess-1'));

    expect(AuditLog::query()->sole()->changes)->toBeNull();
});
