<?php

declare(strict_types=1);

use Database\Seeders\RolePermissionSeeder;

/*
 * "Which store is this session in" - the one piece of store identity every
 * other admin screen gets for free through host/default resolution, but that
 * a client-side screen needs explicitly to build the two payment-settings
 * URLs that carry a store id in the path (CLAUDE.md section 8).
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('reports the store this session resolved to', function (): void {
    [$store, $manager] = storeWithStaff();

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/admin/store')
        ->assertOk()
        ->assertJsonPath('id', $store->id)
        ->assertJsonPath('slug', $store->slug)
        ->assertJsonPath('name', $store->name);
});

it('requires no permission beyond authentication: every screen already discloses this much', function (): void {
    [, $kitchen] = storeWithStaff('KITCHEN_STAFF');

    $this->actingAs($kitchen, 'sanctum')
        ->getJson('/api/v1/admin/store')
        ->assertOk();
});

it('refuses an unauthenticated caller', function (): void {
    $this->getJson('/api/v1/admin/store')->assertUnauthorized();
});
