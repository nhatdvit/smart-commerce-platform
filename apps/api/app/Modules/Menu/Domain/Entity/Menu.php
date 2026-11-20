<?php

declare(strict_types=1);

namespace App\Modules\Menu\Domain\Entity;

use App\Modules\Menu\Domain\Enum\MenuStatus;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Domain\ValueObject\DisplayName;
use App\Shared\Presentation\Http\ErrorCode;

/**
 * A store's menu: the set of variants it offers, each at its own price.
 *
 * Schedules and date exceptions are part of the designed contract but not of
 * this slice, so this entity carries none: a menu here is "what is offered",
 * not yet "when". The next slice adds the windows together with the screen
 * that edits them.
 */
final class Menu
{
    /**
     * @param  list<MenuItem>  $items
     */
    private function __construct(
        public readonly ?string $id,
        public readonly string $storeId,
        public readonly string $name,
        public readonly MenuStatus $status,
        public readonly array $items,
    ) {}

    /**
     * @param  list<MenuItem>  $items
     */
    public static function create(
        ?string $id,
        string $storeId,
        string $name,
        MenuStatus $status,
        array $items,
    ): self {
        $name = DisplayName::normalise($name);

        if ($name === '') {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A menu needs a name',
                [['field' => 'name', 'message' => 'Vui lòng nhập tên thực đơn.']],
            );
        }

        self::assertEachVariantAppearsOnce($items);

        return new self($id, $storeId, $name, $status, array_values($items));
    }

    /** @param list<MenuItem> $items */
    private static function assertEachVariantAppearsOnce(array $items): void
    {
        $seen = [];

        foreach ($items as $item) {
            if (isset($seen[$item->variantId])) {
                // One variant with two prices on one menu has no correct
                // answer, which is why the database says the same thing.
                throw new BusinessRuleViolation(
                    ErrorCode::ValidationFailed,
                    'A variant can only be listed once on a menu',
                    [['field' => 'items', 'message' => 'Mỗi món chỉ được xuất hiện một lần trong thực đơn.']],
                );
            }

            $seen[$item->variantId] = true;
        }
    }
}
