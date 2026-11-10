<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Enum\MembershipStatus;
use App\Modules\Identity\Infrastructure\Persistence\Model\Role;
use App\Modules\Identity\Infrastructure\Persistence\Model\StoreMember;
use App\Modules\Identity\Infrastructure\Persistence\Model\User;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Route;

/*
 * Store-scoped authorization (CLAUDE.md §8, §12).
 *
 * This is the boundary that stops a cashier at one store acting on another.
 * Each case here is a privilege-escalation bug if it ever flips.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    // A route that exercises the real middleware stack, defined per test so
    // the assertions do not depend on business endpoints that do not exist
    // yet in this milestone.
    Route::middleware(['api', 'auth:sanctum', 'store.resolve', 'store.can:payment.confirm'])
        ->get('/test/stores/{store}/confirm', fn () => response()->json(['ok' => true]));
});

function member(Store $store, User $user, string $roleCode, MembershipStatus $status = MembershipStatus::Active): void
{
    StoreMember::query()->create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'role_id' => Role::query()->where('code', $roleCode)->sole()->id,
        'status' => $status->value,
    ]);
}

it('allows a cashier to act in the store they staff', function (): void {
    $store = Store::factory()->create();
    $cashier = User::factory()->create();
    member($store, $cashier, 'CASHIER');

    $this->actingAs($cashier, 'sanctum')
        ->getJson("/test/stores/{$store->id}/confirm")
        ->assertOk();
});

it('denies the same cashier in a store they do not staff', function (): void {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $cashier = User::factory()->create();
    member($storeA, $cashier, 'CASHIER');

    // Same user, same permission, different store: this must fail.
    $this->actingAs($cashier, 'sanctum')
        ->getJson("/test/stores/{$storeB->id}/confirm")
        ->assertForbidden()
        ->assertJsonPath('code', 'STORE_SCOPE_DENIED');
});

it('denies a role that lacks the permission even in its own store', function (): void {
    $store = Store::factory()->create();
    $kitchen = User::factory()->create();
    member($store, $kitchen, 'KITCHEN_STAFF');

    $this->actingAs($kitchen, 'sanctum')
        ->getJson("/test/stores/{$store->id}/confirm")
        ->assertForbidden()
        ->assertJsonPath('code', 'STORE_SCOPE_DENIED');
});

it('denies a suspended membership', function (): void {
    $store = Store::factory()->create();
    $cashier = User::factory()->create();
    member($store, $cashier, 'CASHIER', MembershipStatus::Suspended);

    $this->actingAs($cashier, 'sanctum')
        ->getJson("/test/stores/{$store->id}/confirm")
        ->assertForbidden();
});

it('denies a blocked user who still holds a valid membership', function (): void {
    $store = Store::factory()->create();
    $cashier = User::factory()->blocked()->create();
    member($store, $cashier, 'CASHIER');

    $this->actingAs($cashier, 'sanctum')
        ->getJson("/test/stores/{$store->id}/confirm")
        ->assertForbidden();
});

it('allows a platform-wide SUPER_ADMIN in any store, without a membership', function (): void {
    $store = Store::factory()->create();
    $admin = User::factory()->create();
    $admin->globalRoles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->sole()->id);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/test/stores/{$store->id}/confirm")
        ->assertOk();
});

it('rejects an unauthenticated request before any store lookup', function (): void {
    $store = Store::factory()->create();

    $this->getJson("/test/stores/{$store->id}/confirm")
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('ignores a store id supplied in the query string', function (): void {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $cashier = User::factory()->create();
    member($storeA, $cashier, 'CASHIER');

    // The route names store B. Appending store A to the query string must not
    // widen the scope: a query parameter is attacker-controlled.
    $this->actingAs($cashier, 'sanctum')
        ->getJson("/test/stores/{$storeB->id}/confirm?store_id={$storeA->id}&store={$storeA->id}")
        ->assertForbidden()
        ->assertJsonPath('code', 'STORE_SCOPE_DENIED');
});

it('reports an inactive store as not found rather than disabled', function (): void {
    $store = Store::factory()->inactive()->create();
    $owner = User::factory()->create();
    member($store, $owner, 'STORE_OWNER');

    // Existence of a store is not public information.
    $this->actingAs($owner, 'sanctum')
        ->getJson("/test/stores/{$store->id}/confirm")
        ->assertNotFound()
        ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
});

it('gives the same answer whether the user lacks permission or is not a member', function (): void {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();

    $nonMember = User::factory()->create();
    member($storeA, $nonMember, 'CASHIER');

    $wrongRole = User::factory()->create();
    member($storeB, $wrongRole, 'KITCHEN_STAFF');

    // Identical responses, so the API cannot be used to probe who staffs what.
    $notAMember = $this->actingAs($nonMember, 'sanctum')
        ->getJson("/test/stores/{$storeB->id}/confirm");

    $lacksPermission = $this->actingAs($wrongRole, 'sanctum')
        ->getJson("/test/stores/{$storeB->id}/confirm");

    expect($notAMember->status())->toBe($lacksPermission->status())
        ->and($notAMember->json('code'))->toBe($lacksPermission->json('code'));
});
