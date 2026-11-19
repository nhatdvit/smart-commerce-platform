<?php

declare(strict_types=1);

namespace App\Modules\Menu\Domain\Entity;

use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Domain\ValueObject\Money;
use App\Shared\Presentation\Http\ErrorCode;

/**
 * A product variant offered on a menu, at this store's price.
 *
 * This - not the variant - is what a cart line will reference, because this is
 * where the price a customer actually pays lives (CLAUDE.md section 6).
 *
 * `availableQuantity` and `dailyCapacity` are today's operational numbers:
 * what is left, and what was prepared. Both null means this item is not
 * counted at all, which is the right answer for a bottled drink.
 */
final class MenuItem
{
    private function __construct(
        public readonly ?string $id,
        public readonly string $variantId,
        public readonly Money $price,
        public readonly bool $isAvailable,
        public readonly ?int $availableQuantity,
        public readonly ?int $dailyCapacity,
        public readonly int $sortOrder,
    ) {}

    public static function create(
        ?string $id,
        string $variantId,
        Money $price,
        bool $isAvailable = true,
        ?int $availableQuantity = null,
        ?int $dailyCapacity = null,
        int $sortOrder = 0,
    ): self {
        if ($price->isNegative()) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A menu price cannot be negative',
                [['field' => 'selling_price_amount', 'message' => 'Giá bán phải là số nguyên đồng, không âm.']],
            );
        }

        foreach (['available_quantity' => $availableQuantity, 'daily_capacity' => $dailyCapacity] as $field => $value) {
            if ($value !== null && $value < 0) {
                throw new BusinessRuleViolation(
                    ErrorCode::ValidationFailed,
                    'Portion counts cannot be negative',
                    [['field' => $field, 'message' => 'Số suất phải là số nguyên, không âm.']],
                );
            }
        }

        // What is left can never exceed what was prepared. Letting it through
        // would promise portions nobody cooked.
        if ($availableQuantity !== null && $dailyCapacity !== null && $availableQuantity > $dailyCapacity) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'Remaining portions cannot exceed the portions prepared',
                [[
                    'field' => 'available_quantity',
                    'message' => 'Số suất còn lại không thể nhiều hơn số suất đã chuẩn bị.',
                ]],
            );
        }

        return new self($id, $variantId, $price, $isAvailable, $availableQuantity, $dailyCapacity, $sortOrder);
    }

    /**
     * The same item with new availability numbers.
     *
     * Running out of pork at 08:40 is a one-field operational fact, not a menu
     * redesign, so it has its own narrow operation (CLAUDE.md section 6).
     */
    public function withAvailability(bool $isAvailable, ?int $availableQuantity, ?int $dailyCapacity): self
    {
        return self::create(
            $this->id,
            $this->variantId,
            $this->price,
            $isAvailable,
            $availableQuantity,
            $dailyCapacity,
            $this->sortOrder,
        );
    }
}
