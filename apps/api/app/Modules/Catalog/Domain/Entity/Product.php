<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Entity;

use App\Modules\Catalog\Domain\Enum\CatalogStatus;
use App\Modules\Catalog\Domain\ValueObject\ProductAttributes;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Domain\ValueObject\DisplayName;
use App\Shared\Presentation\Http\ErrorCode;

/**
 * A product as the business understands it, independent of Eloquent and HTTP.
 *
 * Master data: it exists whether or not it is on today's menu. The invariants
 * it protects are the ones that have to hold wherever a product comes from -
 * an API call, a seeder, an import - which is why they live here rather than in
 * a Form Request (CLAUDE.md sections 3 and 16).
 */
final class Product
{
    /**
     * @param  list<ProductVariant>  $variants
     */
    private function __construct(
        public readonly ?string $id,
        public readonly string $storeId,
        public readonly ?string $categoryId,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $description,
        public readonly CatalogStatus $status,
        public readonly bool $inventoryTracking,
        public readonly ?string $imageUrl,
        public readonly ?string $icon,
        public readonly ProductAttributes $attributes,
        public readonly array $variants,
        /**
         * The category as stored, when it has been loaded.
         *
         * A write only knows the id; a read carries the whole thing, so a list
         * screen does not have to resolve every id against a second request.
         */
        public readonly ?Category $category = null,
    ) {}

    /**
     * @param  string|null  $id  null for a product that does not exist yet
     * @param  list<ProductVariant>  $variants
     */
    public static function create(
        ?string $id,
        string $storeId,
        ?string $categoryId,
        string $name,
        string $slug,
        ?string $description,
        CatalogStatus $status,
        bool $inventoryTracking,
        ?string $imageUrl,
        ?string $icon,
        ProductAttributes $attributes,
        array $variants,
        ?Category $category = null,
    ): self {
        $name = self::normaliseName($name);

        if ($name === '') {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A product needs a name',
            );
        }

        // A product with no variant has no price and cannot be sold. One
        // default variant is the minimum, which is also what the menu form
        // sends for a single-price dish.
        if ($variants === []) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A product needs at least one variant',
            );
        }

        self::assertVariantNamesAreDistinct($variants);
        self::assertOneThingIsShown($imageUrl, $icon);

        return new self(
            $id,
            $storeId,
            $categoryId,
            $name,
            $slug,
            $description === null || trim($description) === '' ? null : trim($description),
            $status,
            $inventoryTracking,
            $imageUrl,
            $icon,
            $attributes,
            array_values($variants),
            $category,
        );
    }

    /** Whether a menu may list this product at all. */
    public function isListable(): bool
    {
        return $this->status->isListable();
    }

    /** Collapses the whitespace a pasted name usually carries. */
    public static function normaliseName(string $name): string
    {
        return DisplayName::normalise($name);
    }

    /**
     * A product shows a photo or an icon, never both.
     *
     * Holding both would leave what a customer sees up to whichever client
     * rendered it, and the two would drift: a shop that uploads a photo and
     * forgets the icon would still see the icon on a screen that preferred it.
     * The rule lives here rather than in a Form Request so it also holds for a
     * seeder or an import.
     */
    private static function assertOneThingIsShown(?string $imageUrl, ?string $icon): void
    {
        if ($imageUrl !== null && $icon !== null) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A product shows either a photo or an icon, not both',
                [['field' => 'image_url', 'message' => 'Chọn một: ảnh tải lên hoặc icon.']],
            );
        }
    }

    /** @param list<ProductVariant> $variants */
    private static function assertVariantNamesAreDistinct(array $variants): void
    {
        $seen = [];

        foreach ($variants as $variant) {
            // Case-insensitive, like every other name comparison here: two
            // variants called "Lớn" and "lớn" would be indistinguishable on a
            // receipt, and the database index uses this very key.
            $key = DisplayName::key($variant->name);

            if (isset($seen[$key])) {
                throw new BusinessRuleViolation(
                    ErrorCode::ValidationFailed,
                    sprintf('Variant "%s" is listed twice', $variant->name),
                );
            }

            $seen[$key] = true;
        }
    }
}
