<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Entity;

use App\Modules\Catalog\Domain\Enum\CatalogStatus;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Domain\ValueObject\Money;
use App\Shared\Presentation\Http\ErrorCode;

/**
 * One sellable shape of a product: a size, a portion, a bottle.
 *
 * The price here is master data - a default. What a customer pays is the menu
 * item's price, because that is per store and per menu (CLAUDE.md section 6).
 */
final class ProductVariant
{
    private function __construct(
        public readonly ?string $id,
        public readonly ?string $sku,
        public readonly string $name,
        public readonly Money $basePrice,
        public readonly CatalogStatus $status,
        public readonly int $sortOrder,
    ) {}

    /**
     * @param  string|null  $id  null for a variant that does not exist yet
     */
    public static function create(
        ?string $id,
        ?string $sku,
        string $name,
        Money $basePrice,
        CatalogStatus $status,
        int $sortOrder = 0,
    ): self {
        $name = trim($name);

        if ($name === '') {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A variant needs a name',
            );
        }

        // Negative money is not a discount, it is a mistake: a variant that
        // pays the customer to take it would flow straight into order totals.
        if ($basePrice->isNegative()) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A variant price cannot be negative',
            );
        }

        return new self($id, $sku === null || trim($sku) === '' ? null : trim($sku), $name, $basePrice, $status, $sortOrder);
    }
}
