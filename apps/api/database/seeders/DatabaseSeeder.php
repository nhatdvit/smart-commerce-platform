<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seed entrypoint.
 *
 * Reference data (roles and permissions) is required everywhere, including
 * production: without it nobody can be granted anything.
 *
 * Demo data is created only outside production (CLAUDE.md §7).
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        if (app()->isProduction()) {
            // Still reference data: a store with no menu cannot sell anything,
            // and nothing in the contract lets an operator create one.
            $this->call(DefaultMenuSeeder::class);
            $this->command?->info('Production environment: reference data only, no demo data.');

            return;
        }

        $this->call(DemoStoreSeeder::class);
        // After the demo store exists, so it gets a menu too.
        $this->call(DefaultMenuSeeder::class);
    }
}
