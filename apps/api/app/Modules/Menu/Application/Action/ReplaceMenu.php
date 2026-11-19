<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application\Action;

use App\Modules\Catalog\Application\Contract\ProvidesVariantSnapshots;
use App\Modules\Menu\Application\DTO\MenuInput;
use App\Modules\Menu\Application\DTO\MenuItemInput;
use App\Modules\Menu\Domain\Contract\MenuRepository;
use App\Modules\Menu\Domain\Entity\Menu;
use App\Modules\Menu\Domain\Entity\MenuItem;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a menu's name, status and items.
 *
 * Changing a menu never touches orders already placed: those carry their own
 * snapshots of name, variant and price (CLAUDE.md section 6).
 *
 * Every variant is checked through Catalog's exported read model rather than by
 * joining its tables, so the module boundary holds (section 3). The check is
 * not a formality: it is what stops a menu listing a variant from another store
 * or one that has been retired.
 */
final readonly class ReplaceMenu
{
    public function __construct(
        private MenuRepository $menus,
        private ProvidesVariantSnapshots $catalog,
        private RecordsAuditTrail $audit,
    ) {}

    public function handle(string $storeId, string $menuId, MenuInput $input): Menu
    {
        return DB::transaction(function () use ($storeId, $menuId, $input): Menu {
            $existing = $this->menus->findInStore($storeId, $menuId);

            if ($existing === null) {
                throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Menu not found');
            }

            $this->assertVariantsAreListable($storeId, $input->items);

            // Today's portion counts belong to the kitchen, not to whoever is
            // editing prices: a field the caller did not mention keeps the
            // value it already had.
            $current = [];

            foreach ($existing->items as $item) {
                $current[$item->variantId] = $item;
            }

            $menu = $this->menus->replace(Menu::create(
                $menuId,
                $storeId,
                $input->name,
                $input->status,
                array_map(
                    static function (MenuItemInput $item) use ($current): MenuItem {
                        $previous = $current[$item->variantId] ?? null;

                        return MenuItem::create(
                            $item->id,
                            $item->variantId,
                            $item->sellingPrice,
                            $item->isAvailable,
                            $item->quantityGiven ? $item->availableQuantity : $previous?->availableQuantity,
                            $item->capacityGiven ? $item->dailyCapacity : $previous?->dailyCapacity,
                            $item->sortOrder,
                        );
                    },
                    $input->items,
                ),
            ));

            $this->audit->record(
                AuditEntry::for('menu.menu.replace', 'menu', $menuId, [
                    'name' => $menu->name,
                    'status' => $menu->status->value,
                    'items' => count($menu->items),
                    'items_before' => count($existing->items),
                ])->inStore($storeId),
            );

            return $menu;
        });
    }

    /**
     * @param  list<MenuItemInput>  $items
     */
    private function assertVariantsAreListable(string $storeId, array $items): void
    {
        if ($items === []) {
            return;
        }

        $snapshots = $this->catalog->forStore(
            $storeId,
            array_values(array_map(static fn (MenuItemInput $item): string => $item->variantId, $items)),
        );

        $details = [];

        foreach ($items as $index => $item) {
            $snapshot = $snapshots[$item->variantId] ?? null;

            if ($snapshot === null) {
                // Absent covers both "does not exist" and "belongs to another
                // store", and the message keeps them indistinguishable.
                $details[] = [
                    'field' => sprintf('items.%d.variant_id', $index),
                    'message' => 'Món này không tồn tại trong cửa hàng.',
                ];

                continue;
            }

            if (! $snapshot->isListable) {
                $details[] = [
                    'field' => sprintf('items.%d.variant_id', $index),
                    'message' => 'Món này đã ngừng bán, không thể đưa lên thực đơn.',
                ];
            }
        }

        if ($details !== []) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'One or more items cannot be put on this menu',
                $details,
            );
        }
    }
}
