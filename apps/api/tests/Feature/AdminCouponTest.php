<?php

declare(strict_types=1);

use App\Modules\Pricing\Domain\Enum\CouponStatus;
use App\Modules\Pricing\Infrastructure\Persistence\Model\Coupon;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use App\Shared\Infrastructure\Persistence\Model\AuditLog;
use Database\Seeders\RolePermissionSeeder;

/*
 * Admin discount codes, against real PostgreSQL.
 *
 * SQLite could not run these: the rules that keep a coupon from costing a
 * shop money - a percentage inside 1..100, a fixed amount no larger than the
 * order it requires, a counter that cannot pass its limit - are CHECK
 * constraints as well as domain rules (CLAUDE.md section 12).
 *
 * `storeWithStaff()` lives in AdminProductTest.php; Pest loads every file in
 * the suite, so helpers are shared rather than duplicated.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/**
 * A valid coupon payload, shaped like the admin form's.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function couponPayload(array $overrides = []): array
{
    return array_replace([
        'code' => 'SANG10',
        'description' => 'Giảm 10% cho đơn sáng',
        'discount_type' => 'PERCENTAGE',
        'discount_value' => 10,
        'max_discount_amount' => 20_000,
        'min_order_amount' => 100_000,
        'usage_limit' => 50,
        'starts_at' => null,
        'ends_at' => null,
        'status' => 'ACTIVE',
    ], $overrides);
}

it('creates a code and stores it upper case', function (): void {
    [$store, $manager] = storeWithStaff();

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/coupons', couponPayload(['code' => 'sang10']))
        ->assertCreated()
        ->assertJsonPath('code', 'SANG10')
        ->assertJsonPath('discount_type', 'PERCENTAGE')
        ->assertJsonPath('discount_value', 10)
        ->assertJsonPath('max_discount_amount.amount', '20000')
        ->assertJsonPath('min_order_amount.amount', '100000')
        ->assertJsonPath('usage_limit', 50)
        // Nothing has redeemed anything: checkout does the counting, and it
        // is not built yet.
        ->assertJsonPath('redeemed_count', 0)
        ->assertJsonPath('remaining_uses', 50)
        ->assertJsonPath('availability', 'RUNNING');

    expect(Coupon::query()->where('store_id', $store->id)->sole()->code)->toBe('SANG10');
});

it('refuses a code the store already has, whatever the case', function (): void {
    [$store, $manager] = storeWithStaff();
    Coupon::factory()->inStore($store->id)->code('SANG10')->create();

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/coupons', couponPayload(['code' => 'sang10']))
        ->assertStatus(422)
        ->assertJsonPath('details.0.field', 'code');

    expect(Coupon::query()->count())->toBe(1);
});

it('lets two stores use the same code', function (): void {
    [$store, $manager] = storeWithStaff();
    Coupon::factory()->inStore(Store::factory()->create()->id)->code('SANG10')->create();

    // The uniqueness rule is per store: another shop's campaign is not this
    // shop's business.
    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/coupons', couponPayload())
        ->assertCreated();

    expect(Coupon::query()->where('store_id', $store->id)->count())->toBe(1);
});

it('refuses a percentage above one hundred', function (): void {
    [, $manager] = storeWithStaff();

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/coupons', couponPayload(['discount_value' => 150]))
        ->assertStatus(422)
        ->assertJsonPath('details.0.field', 'discount_value');

    expect(Coupon::query()->count())->toBe(0);
});

it('refuses a fixed discount larger than the order it requires', function (): void {
    [, $manager] = storeWithStaff();

    // Otherwise the shop pays the customer to order breakfast.
    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/coupons', couponPayload([
            'code' => 'GIAM99K',
            'discount_type' => 'FIXED_AMOUNT',
            'discount_value' => 99_000,
            'max_discount_amount' => null,
            'min_order_amount' => 50_000,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('details.0.field', 'discount_value');
});

it('refuses a ceiling on a fixed amount', function (): void {
    [, $manager] = storeWithStaff();

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/coupons', couponPayload([
            'code' => 'GIAM5K',
            'discount_type' => 'FIXED_AMOUNT',
            'discount_value' => 5_000,
            'max_discount_amount' => 10_000,
            'min_order_amount' => 50_000,
        ]))
        ->assertStatus(422)
        ->assertJsonPath('details.0.field', 'max_discount_amount');
});

it('refuses a window that ends before it starts', function (): void {
    [, $manager] = storeWithStaff();

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/coupons', couponPayload([
            'starts_at' => now()->addDay()->toIso8601ZuluString(),
            'ends_at' => now()->toIso8601ZuluString(),
        ]))
        ->assertStatus(422)
        ->assertJsonPath('details.0.field', 'ends_at');
});

it('refuses a per-customer limit, which nothing can enforce yet', function (): void {
    [, $manager] = storeWithStaff();

    // Counting per customer needs an identity, and guest checkout has none.
    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/coupons', couponPayload(['usage_limit_per_customer' => 1]))
        ->assertStatus(422);
});

it('lists this store codes only, newest first', function (): void {
    [$store, $manager] = storeWithStaff();
    $first = Coupon::factory()->inStore($store->id)->code('GIAM5K')->create();
    $second = Coupon::factory()->inStore($store->id)->code('SANG10')->create();
    Coupon::factory()->inStore(Store::factory()->create()->id)->code('KHAC10')->create();

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/coupons')
        ->assertOk()
        ->assertJsonCount(2, 'items')
        ->assertJsonPath('items.0.id', $second->id)
        ->assertJsonPath('items.1.id', $first->id)
        ->assertJsonPath('has_more', false);
});

it('filters by status and searches by code', function (): void {
    [$store, $manager] = storeWithStaff();
    Coupon::factory()->inStore($store->id)->code('GIAM5K')->create();
    $paused = Coupon::factory()->inStore($store->id)->code('SANG10')
        ->status(CouponStatus::Paused)->create();

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/coupons?status[]=PAUSED')
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.id', $paused->id);

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/coupons?q=sang')
        ->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.code', 'SANG10');
});

it('reports why a code is not usable right now', function (): void {
    [$store, $manager] = storeWithStaff();
    $expired = Coupon::factory()->inStore($store->id)->code('HETHAN')->endedYesterday()->create();
    $scheduled = Coupon::factory()->inStore($store->id)->code('SAPTOI')->startsTomorrow()->create();
    $exhausted = Coupon::factory()->inStore($store->id)->code('HETLUOT')->used(20, 20)->create();
    $paused = Coupon::factory()->inStore($store->id)->code('TAMDUNG')
        ->status(CouponStatus::Paused)->create();

    $states = collect(
        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/admin/coupons')
            ->assertOk()
            ->json('items'),
    )->pluck('availability', 'id');

    // Four different conversations with a customer, kept apart.
    expect($states[$expired->id])->toBe('EXPIRED')
        ->and($states[$scheduled->id])->toBe('SCHEDULED')
        ->and($states[$exhausted->id])->toBe('EXHAUSTED')
        ->and($states[$paused->id])->toBe('PAUSED');
});

it('edits what a code gives away and pauses it', function (): void {
    [$store, $manager] = storeWithStaff();
    $coupon = Coupon::factory()->inStore($store->id)->code('SANG10')->percentage(10, 20_000)->create();

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/coupons/{$coupon->id}", couponPayload([
            'discount_value' => 15,
            'status' => 'PAUSED',
        ]))
        ->assertOk()
        ->assertJsonPath('discount_value', 15)
        ->assertJsonPath('status', 'PAUSED')
        ->assertJsonPath('availability', 'PAUSED');

    expect($coupon->refresh()->discount_value)->toBe(15)
        ->and($coupon->status)->toBe(CouponStatus::Paused)
        ->and(AuditLog::query()->where('action', 'pricing.coupon.update')->count())->toBe(1);
});

it('refuses to rename a code that is already in customers hands', function (): void {
    [$store, $manager] = storeWithStaff();
    $coupon = Coupon::factory()->inStore($store->id)->code('SANG10')->create();

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/coupons/{$coupon->id}", couponPayload(['code' => 'SANG20']))
        ->assertStatus(422)
        ->assertJsonPath('details.0.field', 'code');

    expect($coupon->refresh()->code)->toBe('SANG10');
});

it('refuses a limit below the redemptions already counted', function (): void {
    [$store, $manager] = storeWithStaff();
    $coupon = Coupon::factory()->inStore($store->id)->code('SANG10')->used(18, 100)->create();

    // Clamping would make the stored counter a lie.
    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/coupons/{$coupon->id}", couponPayload(['usage_limit' => 10]))
        ->assertStatus(422)
        ->assertJsonPath('details.0.field', 'usage_limit');

    expect($coupon->refresh()->usage_limit)->toBe(100);
});

it('keeps the redemption counter out of the operator hands', function (): void {
    [$store, $manager] = storeWithStaff();
    $coupon = Coupon::factory()->inStore($store->id)->code('SANG10')->used(18, 100)->create();

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/coupons/{$coupon->id}", couponPayload([
            'usage_limit' => 100,
            'redeemed_count' => 0,
        ]))
        ->assertOk()
        // The field is not in the accepted set, so it changes nothing.
        ->assertJsonPath('redeemed_count', 18);

    expect($coupon->refresh()->redeemed_count)->toBe(18);
});

it('reports another store code as not found', function (): void {
    [, $manager] = storeWithStaff();
    $theirs = Coupon::factory()->inStore(Store::factory()->create()->id)->code('SANG10')->create();

    $this->actingAs($manager, 'sanctum')
        ->putJson("/api/v1/admin/coupons/{$theirs->id}", couponPayload())
        ->assertNotFound()
        ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
});

it('lets a cashier read codes but not create them', function (): void {
    [$store, $cashier] = storeWithStaff('CASHIER');
    Coupon::factory()->inStore($store->id)->code('SANG10')->create();

    // Reading a code out at the counter is the job; inventing a discount is
    // deciding to give money away.
    $this->actingAs($cashier, 'sanctum')
        ->getJson('/api/v1/admin/coupons')
        ->assertOk()
        ->assertJsonCount(1, 'items');

    $this->actingAs($cashier, 'sanctum')
        ->postJson('/api/v1/admin/coupons', couponPayload(['code' => 'CASHIER1']))
        ->assertForbidden()
        ->assertJsonPath('code', 'STORE_SCOPE_DENIED');

    expect(Coupon::query()->count())->toBe(1);
});

it('refuses kitchen staff entirely', function (): void {
    [$store, $kitchen] = storeWithStaff('KITCHEN_STAFF');
    Coupon::factory()->inStore($store->id)->code('SANG10')->create();

    $this->actingAs($kitchen, 'sanctum')
        ->getJson('/api/v1/admin/coupons')
        ->assertForbidden();
});

it('records a created code in the audit trail', function (): void {
    [, $manager] = storeWithStaff();

    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/admin/coupons', couponPayload())
        ->assertCreated();

    $entry = AuditLog::query()->where('action', 'pricing.coupon.create')->sole();

    expect($entry->actor_id)->toBe($manager->id)
        ->and($entry->changes['code'] ?? null)->toBe('SANG10');
});

it('refuses an unauthenticated caller', function (): void {
    $this->getJson('/api/v1/admin/coupons')->assertUnauthorized();
    $this->postJson('/api/v1/admin/coupons', couponPayload())->assertUnauthorized();
});
