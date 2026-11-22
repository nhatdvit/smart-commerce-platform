<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Persistence\Model\User;
use App\Modules\Loyalty\Domain\Enum\EntryType;
use App\Modules\Loyalty\Infrastructure\Persistence\Model\LedgerEntryModel;
use App\Modules\Loyalty\Infrastructure\Persistence\Model\RewardModel;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;

/*
 * A signed-in customer's own profile, password and loyalty standing.
 *
 * `earnFor()` lives in AdminLoyaltyMemberTest.php; Pest loads every file in
 * the suite, so helpers are shared rather than duplicated.
 */

const AUTH_HEADERS = ['Referer' => 'http://localhost', 'Origin' => 'http://localhost'];

beforeEach(function (): void {
    config(['sanctum.stateful' => ['localhost']]);
});

it("shows the signed-in customer's own profile, never the password hash", function (): void {
    $user = User::factory()->phoneOnly()->create(['phone' => '0909777001', 'full_name' => 'Trần Bảo Ngọc']);

    $response = $this->actingAs($user, 'web')
        ->withHeaders(AUTH_HEADERS)
        ->getJson('/api/v1/customer/profile')
        ->assertOk()
        ->assertJsonPath('name', 'Trần Bảo Ngọc')
        ->assertJsonPath('phone', '0909777001');

    expect($response->json())->not->toHaveKey('password_hash');
});

it('refuses an unauthenticated caller for profile, password and loyalty', function (): void {
    $this->withHeaders(AUTH_HEADERS)->getJson('/api/v1/customer/profile')->assertUnauthorized();
    $this->withHeaders(AUTH_HEADERS)
        ->putJson('/api/v1/customer/password', ['current_password' => 'a', 'new_password' => 'bbbbbbbb'])
        ->assertUnauthorized();
    $this->withHeaders(AUTH_HEADERS)->getJson('/api/v1/customer/loyalty')->assertUnauthorized();
});

it('updates the full name only', function (): void {
    $user = User::factory()->phoneOnly()->create(['phone' => '0909777002', 'full_name' => 'Tên cũ']);

    $this->actingAs($user, 'web')
        ->withHeaders(AUTH_HEADERS)
        ->putJson('/api/v1/customer/profile', ['name' => 'Tên mới'])
        ->assertOk()
        ->assertJsonPath('name', 'Tên mới')
        ->assertJsonPath('phone', '0909777002');

    expect($user->refresh()->full_name)->toBe('Tên mới');
});

it('refuses an empty name', function (): void {
    $user = User::factory()->phoneOnly()->create(['phone' => '0909777003']);

    $this->actingAs($user, 'web')
        ->withHeaders(AUTH_HEADERS)
        ->putJson('/api/v1/customer/profile', ['name' => ''])
        ->assertStatus(422);
});

it('changes the password, and the new one works for the next login', function (): void {
    $user = User::factory()->phoneOnly()->create(['phone' => '0909777004']);

    $this->actingAs($user, 'web')
        ->withHeaders(AUTH_HEADERS)
        ->putJson('/api/v1/customer/password', [
            'current_password' => 'password',
            'new_password' => 'mat-khau-moi-123',
        ])
        ->assertNoContent();

    $this->withHeaders(AUTH_HEADERS)
        ->postJson('/api/v1/auth/login', ['phone' => '0909777004', 'password' => 'mat-khau-moi-123'])
        ->assertOk();
});

it('refuses a password change with the wrong current password, and changes nothing', function (): void {
    $user = User::factory()->phoneOnly()->create(['phone' => '0909777005']);

    $this->actingAs($user, 'web')
        ->withHeaders(AUTH_HEADERS)
        ->putJson('/api/v1/customer/password', [
            'current_password' => 'not-the-password',
            'new_password' => 'mat-khau-moi-123',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    $this->withHeaders(AUTH_HEADERS)
        ->postJson('/api/v1/auth/login', ['phone' => '0909777005', 'password' => 'password'])
        ->assertOk();
});

it('reports the balance and tier the loyalty ledger has for this phone', function (): void {
    $store = Store::factory()->create();
    config(['commerce.default_store_slug' => $store->slug]);
    $user = User::factory()->phoneOnly()->create(['phone' => '0909777006']);
    earnFor($store->id, '0909777006', 'Trần Bảo Ngọc', 150);
    $reward = RewardModel::query()->create(['store_id' => $store->id, 'name' => 'Quà', 'points_cost' => 50]);
    LedgerEntryModel::query()->create([
        'store_id' => $store->id,
        'phone' => '0909777006',
        'type' => EntryType::Redeem,
        'points' => -50,
        'reward_id' => $reward->id,
        'actor_id' => User::factory()->create()->id,
    ]);

    $this->actingAs($user, 'web')
        ->withHeaders(AUTH_HEADERS)
        ->getJson('/api/v1/customer/loyalty')
        ->assertOk()
        ->assertJsonPath('phone', '0909777006')
        ->assertJsonPath('lifetime_earned_points', 150)
        ->assertJsonPath('balance', 100)
        // Clears the default 100-point loyal-tier threshold.
        ->assertJsonPath('tier', 'LOYAL');
});

it("never shows one phone's loyalty standing to another signed-in customer", function (): void {
    $store = Store::factory()->create();
    config(['commerce.default_store_slug' => $store->slug]);
    earnFor($store->id, '0909777007', 'Khách A', 500);
    $otherCustomer = User::factory()->phoneOnly()->create(['phone' => '0909777008']);

    $this->actingAs($otherCustomer, 'web')
        ->withHeaders(AUTH_HEADERS)
        ->getJson('/api/v1/customer/loyalty')
        ->assertOk()
        ->assertJsonPath('phone', '0909777008')
        ->assertJsonPath('balance', 0);
});
