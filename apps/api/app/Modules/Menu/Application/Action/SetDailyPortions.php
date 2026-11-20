<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application\Action;

use App\Modules\Menu\Application\DTO\DailyPortionsInput;
use App\Modules\Menu\Domain\Contract\MenuRepository;
use App\Modules\Menu\Domain\Entity\MenuItem;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Records the morning prep: how many portions of each dish were made today.
 *
 * The whole sheet is one transaction. A kitchen writing down forty bánh mì and
 * thirty xôi means both, and a half-applied sheet is worse than a rejected one:
 * the screen would show numbers nobody prepared, and the shop would take orders
 * it cannot serve (CLAUDE.md section 6).
 *
 * Setting a dish to zero marks it sold out, which is the honest reading of
 * "we made none of it today".
 */
final readonly class SetDailyPortions
{
    public function __construct(
        private MenuRepository $menus,
        private RecordsAuditTrail $audit,
    ) {}

    /**
     * @param  list<DailyPortionsInput>  $lines
     * @return list<MenuItem>
     */
    public function handle(string $storeId, array $lines): array
    {
        return DB::transaction(function () use ($storeId, $lines): array {
            $saved = [];

            foreach ($lines as $line) {
                $item = $this->menus->findItemInStore($storeId, $line->menuItemId);

                if ($item === null) {
                    // One unknown item fails the whole sheet, and the message
                    // does not say whether it belongs to another store.
                    throw new BusinessRuleViolation(
                        ErrorCode::ResourceNotFound,
                        'Menu item not found',
                        [['field' => 'items', 'message' => 'Món này không còn trong thực đơn của cửa hàng.']],
                    );
                }

                $updated = $this->menus->saveItemAvailability($storeId, $item->withAvailability(
                    // Nothing prepared is nothing to sell.
                    $line->preparedQuantity > 0,
                    $line->preparedQuantity,
                    $line->preparedQuantity,
                ));

                $this->audit->record(
                    AuditEntry::for('menu.item.daily-portions', 'menu_item', $line->menuItemId, [
                        'available_quantity' => ['from' => $item->availableQuantity, 'to' => $updated->availableQuantity],
                        'daily_capacity' => ['from' => $item->dailyCapacity, 'to' => $updated->dailyCapacity],
                    ])->inStore($storeId),
                );

                $saved[] = $updated;
            }

            return $saved;
        });
    }
}
