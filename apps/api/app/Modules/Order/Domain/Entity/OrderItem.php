<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Entity;

use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Domain\ValueObject\DisplayName;
use App\Shared\Domain\ValueObject\Money;
use App\Shared\Presentation\Http\ErrorCode;

/**
 * One line of an order: an immutable snapshot of what was agreed.
 *
 * Names and prices are COPIES, not references (CLAUDE.md section 6). The
 * product and variant ids are kept for reporting and are nullable, because the
 * line has to stay readable even if the master data is archived or gone -
 * renaming a dish must never rewrite yesterday's receipts.
 *
 * `lineTotal` is derived here rather than accepted, so it cannot disagree with
 * the price and the quantity it is supposed to summarise. The same formula is a
 * CHECK constraint on `order_items`.
 */
final readonly class OrderItem
{
    private function __construct(
        public ?string $id,
        public ?string $productId,
        public ?string $variantId,
        public string $productName,
        public string $variantName,
        public int $quantity,
        public Money $unitPrice,
        /** Per unit, summed across the line's modifiers. Zero until modifiers land. */
        public Money $modifiersTotal,
        public Money $lineTotal,
        public ?string $note,
        public int $sortOrder,
    ) {}

    public static function create(
        ?string $id,
        ?string $productId,
        ?string $variantId,
        string $productName,
        string $variantName,
        int $quantity,
        Money $unitPrice,
        ?Money $modifiersTotal = null,
        ?string $note = null,
        int $sortOrder = 0,
    ): self {
        $productName = DisplayName::normalise($productName);
        $variantName = DisplayName::normalise($variantName);
        $modifiersTotal ??= Money::zero();

        if ($productName === '') {
            // Without the name the line is unreadable, which defeats the whole
            // point of snapshotting it.
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'An order line needs the product name it was sold under',
                [['field' => 'product_name', 'message' => 'Thiếu tên món trong dòng đơn hàng.']],
            );
        }

        if ($quantity < 1) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'An order line needs a quantity of at least one',
                [['field' => 'quantity', 'message' => 'Số lượng phải từ 1 trở lên.']],
            );
        }

        if ($unitPrice->isNegative() || $modifiersTotal->isNegative()) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'An order line cannot carry a negative price',
                [['field' => 'unit_price', 'message' => 'Giá phải là số nguyên đồng, không âm.']],
            );
        }

        $note = $note === null ? null : trim($note);

        return new self(
            $id,
            $productId,
            $variantId,
            $productName,
            // A dish sold without a size still needs a readable variant name.
            $variantName === '' ? 'Mặc định' : $variantName,
            $quantity,
            $unitPrice,
            $modifiersTotal,
            $unitPrice->plus($modifiersTotal)->times($quantity),
            $note === '' ? null : $note,
            $sortOrder,
        );
    }

    /**
     * A line as stored, with its recorded total trusted.
     *
     * The repository maps rows through this. Recomputing on read would quietly
     * rewrite a historical receipt whose prices were agreed under different
     * rules, so the stored number wins.
     */
    public static function stored(
        string $id,
        ?string $productId,
        ?string $variantId,
        string $productName,
        string $variantName,
        int $quantity,
        Money $unitPrice,
        Money $modifiersTotal,
        Money $lineTotal,
        ?string $note,
        int $sortOrder,
    ): self {
        return new self(
            $id,
            $productId,
            $variantId,
            $productName,
            $variantName,
            $quantity,
            $unitPrice,
            $modifiersTotal,
            $lineTotal,
            $note,
            $sortOrder,
        );
    }
}
