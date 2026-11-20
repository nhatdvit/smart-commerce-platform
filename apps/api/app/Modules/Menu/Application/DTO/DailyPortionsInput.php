<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application\DTO;

/**
 * One line of the morning prep sheet: this dish, this many portions.
 *
 * `preparedQuantity` is what the kitchen actually made today. It sets both of
 * the item's numbers - what was prepared and what is left - because at the
 * start of service those are the same thing, and nothing has been sold yet.
 *
 * Mid-service corrections do NOT come through here: selling eight bánh mì
 * changes what is left without changing what was prepared, and that is the
 * single-item availability operation.
 */
final readonly class DailyPortionsInput
{
    public function __construct(
        public string $menuItemId,
        public int $preparedQuantity,
    ) {}
}
