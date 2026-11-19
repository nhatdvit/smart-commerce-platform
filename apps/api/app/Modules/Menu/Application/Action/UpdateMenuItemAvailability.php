<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application\Action;

use App\Modules\Menu\Domain\Contract\MenuRepository;
use App\Modules\Menu\Domain\Entity\MenuItem;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Marks an item sold out, or sets today's portions.
 *
 * Deliberately separate from replacing the menu: running out of pork at 08:40
 * is a one-field operational fact, and making staff submit a whole menu to
 * express it would guarantee mistakes (CLAUDE.md section 6).
 *
 * Omitting a portion field leaves it as it is. Clearing a count is said
 * explicitly with null, so a client that only wants to flip the sold-out
 * switch cannot wipe the kitchen's numbers by accident.
 */
final readonly class UpdateMenuItemAvailability
{
    public function __construct(
        private MenuRepository $menus,
        private RecordsAuditTrail $audit,
    ) {}

    public function handle(
        string $storeId,
        string $menuItemId,
        bool $isAvailable,
        bool $quantityGiven,
        ?int $availableQuantity,
        bool $capacityGiven,
        ?int $dailyCapacity,
    ): MenuItem {
        return DB::transaction(function () use (
            $storeId,
            $menuItemId,
            $isAvailable,
            $quantityGiven,
            $availableQuantity,
            $capacityGiven,
            $dailyCapacity,
        ): MenuItem {
            $item = $this->menus->findItemInStore($storeId, $menuItemId);

            if ($item === null) {
                throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Menu item not found');
            }

            $updated = $item->withAvailability(
                $isAvailable,
                $quantityGiven ? $availableQuantity : $item->availableQuantity,
                $capacityGiven ? $dailyCapacity : $item->dailyCapacity,
            );

            $saved = $this->menus->saveItemAvailability($storeId, $updated);

            $this->audit->record(
                AuditEntry::for('menu.item.availability', 'menu_item', $menuItemId, [
                    'is_available' => ['from' => $item->isAvailable, 'to' => $saved->isAvailable],
                    'available_quantity' => ['from' => $item->availableQuantity, 'to' => $saved->availableQuantity],
                    'daily_capacity' => ['from' => $item->dailyCapacity, 'to' => $saved->dailyCapacity],
                ])->inStore($storeId),
            );

            return $saved;
        });
    }
}
