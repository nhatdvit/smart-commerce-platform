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
            $this->command?->info('Production environment: reference data only, no demo data.');

            return;
        }

        $this->call(DemoStoreSeeder::class);
    }
}
