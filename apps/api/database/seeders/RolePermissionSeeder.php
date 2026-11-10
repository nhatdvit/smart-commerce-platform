<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Domain\Enum\RoleScope;
use App\Modules\Identity\Infrastructure\Persistence\Model\Permission;
use App\Modules\Identity\Infrastructure\Persistence\Model\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Reference data: the permission catalogue and the five admin roles.
 *
 * Safe in every environment including production, because this is reference
 * data rather than demo data, and it is idempotent: re-running it reconciles
 * the grants instead of duplicating them.
 *
 * The grant set per role is replaced on each run, so deleting a permission from
 * this file actually revokes it rather than leaving a stale grant behind.
 */
final class RolePermissionSeeder extends Seeder
{
    /** @var list<array{code: string, description: string}> */
    private const array PERMISSIONS = [
        ['code' => 'store.read', 'description' => 'View store profile and settings'],
        ['code' => 'store.update', 'description' => 'Update store profile, hours and settings'],
        ['code' => 'store.member.manage', 'description' => 'Add, remove and re-role staff members'],
        ['code' => 'catalog.read', 'description' => 'View categories, products and variants'],
        ['code' => 'catalog.manage', 'description' => 'Create and update catalog master data'],
        ['code' => 'menu.read', 'description' => 'View menus, schedules and availability'],
        ['code' => 'menu.manage', 'description' => 'Publish menus, prices, schedules and sold-out state'],
        ['code' => 'inventory.read', 'description' => 'View daily portions and stock movements'],
        ['code' => 'inventory.manage', 'description' => 'Adjust daily capacity and correct stock'],
        ['code' => 'order.read', 'description' => 'View orders and order history'],
        ['code' => 'order.transition', 'description' => 'Advance or cancel an order status'],
        ['code' => 'kitchen.operate', 'description' => 'Work the kitchen queue: preparing and ready'],
        ['code' => 'payment.read', 'description' => 'View payments and the reconciliation queue'],
        ['code' => 'payment.confirm', 'description' => 'Mark a payment PAID after verifying receipt'],
        ['code' => 'payment.bank-account.manage', 'description' => 'Change the store receiving bank account'],
        ['code' => 'report.read', 'description' => 'View sales and operational reports'],
        ['code' => 'audit.read', 'description' => 'Read the audit log'],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->updateOrCreate(
                ['code' => $permission['code']],
                ['description' => $permission['description']],
            );
        }

        $permissionIds = Permission::query()->pluck('id', 'code');

        foreach ($this->roles() as $definition) {
            /** @var Role $role */
            $role = Role::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'scope' => $definition['scope'],
                    'description' => $definition['description'],
                ],
            );

            $grants = [];

            foreach ($definition['permissions'] as $code) {
                $id = $permissionIds[$code] ?? null;

                if ($id !== null) {
                    $grants[] = [
                        'role_id' => $role->id,
                        'permission_id' => $id,
                        'created_at' => now(),
                    ];
                }
            }

            // Replace the whole set atomically, so a partial failure cannot
            // leave a role holding half its permissions.
            DB::transaction(static function () use ($role, $grants): void {
                DB::table('role_permissions')->where('role_id', $role->id)->delete();

                if ($grants !== []) {
                    DB::table('role_permissions')->insert($grants);
                }
            });
        }
    }

    /**
     * Role to permission mapping.
     *
     * STORE_MANAGER deliberately lacks payment.bank-account.manage: changing
     * where money lands is an owner-level action (CLAUDE.md §6).
     * KITCHEN_STAFF sees no payment or pricing data at all.
     *
     * @return list<array{code: string, name: string, scope: string, description: string, permissions: list<string>}>
     */
    private function roles(): array
    {
        $everything = array_column(self::PERMISSIONS, 'code');

        return [
            [
                'code' => 'SUPER_ADMIN',
                'name' => 'Super administrator',
                'scope' => RoleScope::Global->value,
                'description' => 'Platform-wide administration across every store',
                'permissions' => $everything,
            ],
            [
                'code' => 'STORE_OWNER',
                'name' => 'Store owner',
                'scope' => RoleScope::Store->value,
                'description' => 'Full control of one store, including bank account and staff',
                'permissions' => $everything,
            ],
            [
                'code' => 'STORE_MANAGER',
                'name' => 'Store manager',
                'scope' => RoleScope::Store->value,
                'description' => 'Day-to-day operations. Cannot change the receiving bank account.',
                'permissions' => [
                    'store.read',
                    'catalog.read',
                    'catalog.manage',
                    'menu.read',
                    'menu.manage',
                    'inventory.read',
                    'inventory.manage',
                    'order.read',
                    'order.transition',
                    'kitchen.operate',
                    'payment.read',
                    'payment.confirm',
                    'report.read',
                ],
            ],
            [
                'code' => 'CASHIER',
                'name' => 'Cashier',
                'scope' => RoleScope::Store->value,
                'description' => 'Takes orders and confirms cash or verified bank receipts',
                'permissions' => [
                    'store.read',
                    'menu.read',
                    'order.read',
                    'order.transition',
                    'payment.read',
                    'payment.confirm',
                ],
            ],
            [
                'code' => 'KITCHEN_STAFF',
                'name' => 'Kitchen staff',
                'scope' => RoleScope::Store->value,
                'description' => 'Works the kitchen queue only. No payment or pricing access.',
                'permissions' => [
                    'menu.read',
                    'order.read',
                    'kitchen.operate',
                    'inventory.read',
                ],
            ],
        ];
    }
}
