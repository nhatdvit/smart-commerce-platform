<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Enum\MembershipStatus;
use App\Modules\Identity\Infrastructure\Persistence\Model\Role;
use App\Modules\Identity\Infrastructure\Persistence\Model\StoreMember;
use App\Modules\Identity\Infrastructure\Persistence\Model\User;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use Database\Seeders\RolePermissionSeeder;

/*
 * Session endpoints for the first-party web apps.
 *
 * Cookie authentication, not tokens: the dashboard cannot call a single admin
 * endpoint without a session, so these belong to the same slice as the admin
 * catalog and menu work.
 *
 * Every request here carries an Origin and a Referer, because Sanctum starts a
 * session only for a request from a configured stateful domain and reads that
 * off those headers. A browser always sends them; a test that did not would be
 * exercising a flow no client uses.
 */

const DASHBOARD_HEADERS = ['Referer' => 'http://localhost', 'Origin' => 'http://localhost'];

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    config(['sanctum.stateful' => ['localhost']]);
});

it('starts a session and describes the principal', function (): void {
    $store = Store::factory()->create(['name' => 'Bếp nhà mình']);
    $user = User::factory()->create(['email' => 'manager@example.test', 'full_name' => 'Demo Manager']);

    StoreMember::query()->create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'role_id' => Role::query()->where('code', 'STORE_MANAGER')->sole()->id,
        'status' => MembershipStatus::Active->value,
    ]);

    $this->withHeaders(DASHBOARD_HEADERS)
        ->postJson('/api/v1/auth/login', [
            'email' => 'manager@example.test',
            'password' => 'password',
        ])
        ->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('name', 'Demo Manager')
        ->assertJsonPath('store_memberships.0.store_id', $store->id)
        ->assertJsonPath('store_memberships.0.store_name', 'Bếp nhà mình')
        ->assertJsonPath('store_memberships.0.role', 'STORE_MANAGER');

    $this->assertAuthenticatedAs($user);
});

it('never returns the password hash', function (): void {
    User::factory()->create(['email' => 'manager@example.test']);

    $response = $this->withHeaders(DASHBOARD_HEADERS)
        ->postJson('/api/v1/auth/login', [
            'email' => 'manager@example.test',
            'password' => 'password',
        ])
        ->assertOk();

    expect($response->json())->not->toHaveKey('password_hash')
        ->and($response->json())->not->toHaveKey('password');
});

it('answers a wrong password and an unknown address the same way', function (): void {
    User::factory()->create(['email' => 'manager@example.test']);

    $wrongPassword = $this->withHeaders(DASHBOARD_HEADERS)
        ->postJson('/api/v1/auth/login', [
            'email' => 'manager@example.test',
            'password' => 'not-the-password',
        ]);

    $unknownEmail = $this->withHeaders(DASHBOARD_HEADERS)
        ->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.test',
            'password' => 'password',
        ]);

    // The response must not reveal which addresses have accounts.
    expect($wrongPassword->status())->toBe(422)
        ->and($unknownEmail->status())->toBe(422)
        ->and($wrongPassword->json('message'))->toBe($unknownEmail->json('message'));

    $this->assertGuest();
});

it('refuses a blocked account and leaves no session behind', function (): void {
    User::factory()->blocked()->create(['email' => 'blocked@example.test']);

    $this->withHeaders(DASHBOARD_HEADERS)
        ->postJson('/api/v1/auth/login', [
            'email' => 'blocked@example.test',
            'password' => 'password',
        ])
        ->assertStatus(422);

    $this->assertGuest();
});

it('refuses a login that is not from a first-party app', function (): void {
    User::factory()->create(['email' => 'manager@example.test']);

    // No Origin and no Referer: Sanctum starts no session, so there is nothing
    // to log in to. A clear 422 beats the 500 that reaching for a missing
    // session store would produce.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'manager@example.test',
        'password' => 'password',
    ])->assertStatus(422);

    $this->assertGuest();
});

it('ends the session', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->withHeaders(DASHBOARD_HEADERS)
        ->postJson('/api/v1/auth/logout')
        ->assertNoContent();
});

it('refuses a logout without a session', function (): void {
    $this->withHeaders(DASHBOARD_HEADERS)
        ->postJson('/api/v1/auth/logout')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('logs in a customer by phone - the same guard, a different identity field', function (): void {
    $user = User::factory()->phoneOnly()->create(['phone' => '0909555001', 'full_name' => 'Chị Lan']);

    $this->withHeaders(DASHBOARD_HEADERS)
        ->postJson('/api/v1/auth/login', ['phone' => '0909555001', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('phone', '0909555001')
        ->assertJsonCount(0, 'roles')
        ->assertJsonCount(0, 'store_memberships');

    $this->assertAuthenticatedAs($user);
});

it('answers a wrong password and an unknown phone the same way', function (): void {
    User::factory()->phoneOnly()->create(['phone' => '0909555002']);

    $wrongPassword = $this->withHeaders(DASHBOARD_HEADERS)
        ->postJson('/api/v1/auth/login', ['phone' => '0909555002', 'password' => 'not-the-password']);

    $unknownPhone = $this->withHeaders(DASHBOARD_HEADERS)
        ->postJson('/api/v1/auth/login', ['phone' => '0909555099', 'password' => 'password']);

    expect($wrongPassword->status())->toBe(422)
        ->and($unknownPhone->status())->toBe(422)
        ->and($wrongPassword->json('message'))->toBe($unknownPhone->json('message'));

    $this->assertGuest();
});

it('registers a customer by phone and signs them straight in', function (): void {
    $response = $this->withHeaders(DASHBOARD_HEADERS)
        ->postJson('/api/v1/auth/register', [
            'full_name' => 'Nguyễn Thảo Vy',
            'phone' => '0912345678',
            'password' => 'matkhau123',
        ])
        ->assertCreated()
        ->assertJsonPath('name', 'Nguyễn Thảo Vy')
        ->assertJsonPath('phone', '0912345678')
        ->assertJsonCount(0, 'roles')
        ->assertJsonCount(0, 'store_memberships');

    expect($response->json())->not->toHaveKey('password_hash')
        ->and($response->json())->not->toHaveKey('password');

    $user = User::query()->where('phone', '0912345678')->sole();
    $this->assertAuthenticatedAs($user);
});

it('refuses registering a phone number that is already taken', function (): void {
    User::factory()->phoneOnly()->create(['phone' => '0909555003']);

    $this->withHeaders(DASHBOARD_HEADERS)
        ->postJson('/api/v1/auth/register', [
            'full_name' => 'Người khác',
            'phone' => '0909555003',
            'password' => 'matkhau123',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    $this->assertGuest();
});

it('refuses a phone number that is not a valid Vietnamese mobile number', function (): void {
    $this->withHeaders(DASHBOARD_HEADERS)
        ->postJson('/api/v1/auth/register', [
            'full_name' => 'Người dùng',
            'phone' => '12345',
            'password' => 'matkhau123',
        ])
        ->assertStatus(422);
});

it('refuses a registration password shorter than the floor', function (): void {
    $this->withHeaders(DASHBOARD_HEADERS)
        ->postJson('/api/v1/auth/register', [
            'full_name' => 'Người dùng',
            'phone' => '0909555004',
            'password' => 'short',
        ])
        ->assertStatus(422);

    $this->assertGuest();
});

it('stores a password that the login endpoint can later verify', function (): void {
    // Registration signs the account straight in via Auth::login(), which
    // never checks a hash - this is what actually proves RegisterCustomerAccount
    // wrote a hash that Auth::attempt()'s own verification can match.
    $this->withHeaders(DASHBOARD_HEADERS)
        ->postJson('/api/v1/auth/register', [
            'full_name' => 'Trần Minh Khang',
            'phone' => '0909555005',
            'password' => 'matkhau123',
        ])
        ->assertCreated();

    $this->withHeaders(DASHBOARD_HEADERS)
        ->postJson('/api/v1/auth/login', ['phone' => '0909555005', 'password' => 'matkhau123'])
        ->assertOk()
        ->assertJsonPath('phone', '0909555005');
});

it("reports the signed-in principal's name, phone and roles on GET /me", function (): void {
    $customer = User::factory()->phoneOnly()->create(['phone' => '0909555010', 'full_name' => 'Khách A']);

    $this->actingAs($customer, 'web')
        ->withHeaders(DASHBOARD_HEADERS)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('id', $customer->id)
        ->assertJsonPath('name', 'Khách A')
        ->assertJsonPath('phone', '0909555010')
        ->assertJsonCount(0, 'roles')
        ->assertJsonCount(0, 'store_memberships');
});
