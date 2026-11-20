<?php

declare(strict_types=1);

namespace App\Modules\Menu\Infrastructure\Persistence;

use App\Modules\Menu\Domain\Contract\MenuRepository;
use App\Modules\Menu\Domain\Entity\Menu;
use App\Modules\Menu\Domain\Entity\MenuItem;
use App\Modules\Menu\Infrastructure\Persistence\Model\Menu as MenuModel;
use App\Modules\Menu\Infrastructure\Persistence\Model\MenuItem as MenuItemModel;
use App\Shared\Domain\ValueObject\DisplayName;
use App\Shared\Domain\ValueObject\Money;

/**
 * Eloquent implementation of the menu seam.
 *
 * The only class in the module that knows the menu tables exist. Removal is a
 * timestamp, not a DELETE: an item taken off the menu keeps its row, because an
 * order line will reference it and history has to stay readable (CLAUDE.md
 * section 7).
 *
 * Re-listing a variant restores the row it had rather than inserting a second
 * one - which is also what the unique index on (menu_id, product_variant_id)
 * requires, so the two cannot disagree.
 */
final class EloquentMenuRepository implements MenuRepository
{
    public function findInStore(string $storeId, string $menuId): ?Menu
    {
        $row = MenuModel::query()
            ->where('store_id', $storeId)
            ->whereKey($menuId)
            ->with(['items' => static fn ($query) => $query->listed()->orderBy('sort_order')->orderBy('id')])
            ->first();

        return $row === null ? null : $this->toEntity($row);
    }

    public function allInStore(string $storeId): array
    {
        $menus = MenuModel::query()
            ->where('store_id', $storeId)
            ->with(['items' => static fn ($query) => $query->listed()->orderBy('sort_order')->orderBy('id')])
            ->orderBy('id')
            ->get()
            ->map(fn (MenuModel $row): Menu => $this->toEntity($row))
            ->all();

        return array_values($menus);
    }

    public function replace(Menu $menu): Menu
    {
        $row = MenuModel::query()
            ->where('store_id', $menu->storeId)
            ->whereKey($menu->id)
            ->sole();

        $row->fill([
            'name' => $menu->name,
            'name_key' => DisplayName::key($menu->name),
            'status' => $menu->status->value,
        ])->save();

        /** @var array<string, MenuItemModel> $existing */
        $existing = MenuItemModel::query()
            ->where('store_id', $menu->storeId)
            ->where('menu_id', $row->id)
            ->get()
            ->keyBy('product_variant_id')
            ->all();

        $kept = [];

        foreach ($menu->items as $index => $item) {
            $current = $existing[$item->variantId] ?? null;
            $columns = [
                'store_id' => $menu->storeId,
                'menu_id' => $row->id,
                'product_variant_id' => $item->variantId,
                'selling_price_amount' => $item->price->amount,
                'currency' => $item->price->currency,
                'is_available' => $item->isAvailable,
                'available_quantity' => $item->availableQuantity,
                'daily_capacity' => $item->dailyCapacity,
                'sort_order' => $item->sortOrder !== 0 ? $item->sortOrder : $index,
                // Restores a row that had been taken off the menu before.
                'deleted_at' => null,
            ];

            if ($current === null) {
                MenuItemModel::query()->create($columns);

                continue;
            }

            $current->fill($columns)->save();
            $kept[$current->product_variant_id] = true;
        }

        foreach ($existing as $variantId => $itemRow) {
            if (isset($kept[$variantId]) || $itemRow->deleted_at !== null) {
                continue;
            }

            $itemRow->deleted_at = now()->toImmutable();
            $itemRow->save();
        }

        $reloaded = $this->findInStore($menu->storeId, (string) $menu->id);

        // The row was loaded with sole() above, so it exists; this keeps the
        // return type honest without a nullable leaking to the caller.
        return $reloaded ?? $menu;
    }

    public function findItemInStore(string $storeId, string $menuItemId): ?MenuItem
    {
        $row = MenuItemModel::query()
            ->where('store_id', $storeId)
            ->whereKey($menuItemId)
            ->listed()
            ->first();

        return $row === null ? null : $this->toItemEntity($row);
    }

    public function saveItemAvailability(string $storeId, MenuItem $item): MenuItem
    {
        $row = MenuItemModel::query()
            ->where('store_id', $storeId)
            ->whereKey($item->id)
            ->listed()
            ->sole();

        $row->fill([
            'is_available' => $item->isAvailable,
            'available_quantity' => $item->availableQuantity,
            'daily_capacity' => $item->dailyCapacity,
        ])->save();

        return $this->toItemEntity($row->refresh());
    }

    public function menuIdForItem(string $storeId, string $menuItemId): ?string
    {
        $row = MenuItemModel::query()
            ->where('store_id', $storeId)
            ->whereKey($menuItemId)
            ->listed()
            ->first();

        return $row?->menu_id;
    }

    private function toEntity(MenuModel $row): Menu
    {
        return Menu::create(
            $row->id,
            $row->store_id,
            $row->name,
            $row->status,
            array_values($row->items->map(fn (MenuItemModel $item): MenuItem => $this->toItemEntity($item))->all()),
        );
    }

    private function toItemEntity(MenuItemModel $row): MenuItem
    {
        return MenuItem::create(
            $row->id,
            $row->product_variant_id,
            Money::vnd($row->selling_price_amount),
            $row->is_available,
            $row->available_quantity,
            $row->daily_capacity,
            $row->sort_order,
        );
    }
}
