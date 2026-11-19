<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Menu\Domain\Enum\MenuStatus;
use App\Modules\Menu\Infrastructure\Persistence\Model\Menu;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use App\Shared\Domain\ValueObject\DisplayName;
use Illuminate\Database\Seeder;

/**
 * Every store gets a menu to put items on.
 *
 * Reference data, not demo data, so it is safe in every environment including
 * production: a store with no menu cannot sell anything, and the designed
 * contract has no "create menu" operation - menus are provisioned with the
 * store. Until the Store module creates one as part of opening a store, this
 * seeder is where that happens.
 *
 * Idempotent: re-running it reconciles rather than duplicating.
 */
final class DefaultMenuSeeder extends Seeder
{
    public const string DEFAULT_MENU_NAME = 'Thực đơn hằng ngày';

    public function run(): void
    {
        Store::query()->each(function (Store $store): void {
            // One menu per store at most, matched on the store rather than the
            // name, so renaming it by hand does not grow a second one.
            $existing = Menu::query()->where('store_id', $store->id)->first();

            if ($existing !== null) {
                return;
            }

            Menu::query()->create([
                'store_id' => $store->id,
                'name' => self::DEFAULT_MENU_NAME,
                'name_key' => DisplayName::key(self::DEFAULT_MENU_NAME),
                'status' => MenuStatus::Active->value,
            ]);
        });
    }
}
