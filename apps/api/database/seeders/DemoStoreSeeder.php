<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Domain\Enum\MembershipStatus;
use App\Modules\Identity\Infrastructure\Persistence\Model\Role;
use App\Modules\Identity\Infrastructure\Persistence\Model\StoreMember;
use App\Modules\Identity\Infrastructure\Persistence\Model\User;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use App\Modules\Store\Infrastructure\Persistence\Model\StoreHour;
use App\Modules\Store\Infrastructure\Persistence\Model\StoreSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Demo data for local development and staging.
 *
 * Never runs in production: DatabaseSeeder gates it. Demo accounts with known
 * passwords in a production database would be a backdoor (CLAUDE.md §7).
 */
final class DemoStoreSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoStoreSeeder must never run in production');
        }

        $store = Store::query()->firstOrCreate(
            ['code' => 'HCM-01'],
            [
                'name' => 'Cho Lon Store - District 1',
                'slug' => 'cho-lon-district-1',
                'phone' => '02838220001',
                'address' => '12 Nguyen Hue, District 1, Ho Chi Minh City',
                'timezone' => 'Asia/Ho_Chi_Minh',
                'status' => 'ACTIVE',
            ],
        );

        StoreSetting::query()->updateOrCreate(
            ['store_id' => $store->id],
            [
                'allow_guest_checkout' => true,
                'pickup_enabled' => true,
                'delivery_enabled' => false,
                'order_lead_minutes' => 15,
                'currency' => 'VND',
            ],
        );

        // Breakfast service, every day, 06:00 to 11:00 store-local.
        foreach (range(1, 7) as $dayOfWeek) {
            StoreHour::query()->updateOrCreate(
                [
                    'store_id' => $store->id,
                    'day_of_week' => $dayOfWeek,
                    'open_time' => '06:00:00',
                ],
                [
                    'close_time' => '11:00:00',
                    'is_closed' => false,
                ],
            );
        }

        $this->seedStaff($store->id);
    }

    private function seedStaff(string $storeId): void
    {
        // One account per store-scoped role, so store isolation and permission
        // boundaries can actually be exercised by hand and in tests.
        $staff = [
            ['email' => 'owner@example.test', 'name' => 'Demo Owner', 'role' => 'STORE_OWNER'],
            ['email' => 'manager@example.test', 'name' => 'Demo Manager', 'role' => 'STORE_MANAGER'],
            ['email' => 'cashier@example.test', 'name' => 'Demo Cashier', 'role' => 'CASHIER'],
            ['email' => 'kitchen@example.test', 'name' => 'Demo Kitchen', 'role' => 'KITCHEN_STAFF'],
        ];

        foreach ($staff as $person) {
            $role = Role::query()->where('code', $person['role'])->first();

            if ($role === null) {
                throw new RuntimeException(
                    sprintf('Role %s is missing. Run RolePermissionSeeder first.', $person['role']),
                );
            }

            /** @var User $user */
            $user = User::query()->firstOrCreate(
                ['email' => $person['email']],
                [
                    'full_name' => $person['name'],
                    'status' => 'ACTIVE',
                ],
            );

            // Set separately: password_hash is guarded against mass assignment.
            $user->password_hash = Hash::make('password');
            $user->save();

            StoreMember::query()->updateOrCreate(
                ['store_id' => $storeId, 'user_id' => $user->id],
                ['role_id' => $role->id, 'status' => MembershipStatus::Active->value],
            );
        }

        $this->command?->info('Demo staff seeded with password "password" (local only).');
    }
}
