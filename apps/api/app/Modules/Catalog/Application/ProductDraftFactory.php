<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Application\DTO\ProductInput;
use App\Modules\Catalog\Application\DTO\VariantInput;
use App\Modules\Catalog\Domain\Contract\CategoryRepository;
use App\Modules\Catalog\Domain\Contract\ProductRepository;
use App\Modules\Catalog\Domain\Entity\Product;
use App\Modules\Catalog\Domain\Entity\ProductVariant;
use App\Modules\Catalog\Domain\ValueObject\ProductAttributes;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Support\Str;

/**
 * Turns a product write into a validated domain product.
 *
 * Shared by create and update because the rules are the same for both, and a
 * rule that only holds on one of the two paths is a rule that does not hold.
 * It resolves the category, derives the slug and runs the checks that need to
 * see the rest of the store's catalogue - the duplicate name above all, which
 * is the client-side rule in `apps/admin-web/src/lib/product-form.ts` enforced
 * where it is actually authoritative.
 */
final readonly class ProductDraftFactory
{
    public function __construct(
        private ProductRepository $products,
        private CategoryRepository $categories,
    ) {}

    /**
     * @param  string|null  $productId  the product being edited, or null when creating
     */
    public function build(string $storeId, ?string $productId, ProductInput $input): Product
    {
        $name = Product::normaliseName($input->name);

        if ($this->products->liveNameExists($storeId, $name, $productId)) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'This store already sells a product with that name',
                [['field' => 'name', 'message' => 'Thực đơn đã có món khác cùng tên.']],
            );
        }

        return Product::create(
            $productId,
            $storeId,
            $this->resolveCategoryId($storeId, $input),
            $name,
            $this->resolveSlug($storeId, $productId, $name, $input->slug),
            $input->description,
            $input->status,
            $input->inventoryTracking,
            $input->imageUrl,
            $input->icon,
            $input->attributes ?? ProductAttributes::empty(),
            array_map(
                static fn (VariantInput $variant): ProductVariant => ProductVariant::create(
                    $variant->id,
                    $variant->sku,
                    $variant->name,
                    $variant->basePrice,
                    $variant->status,
                    $variant->sortOrder,
                ),
                $input->variants,
            ),
        );
    }

    private function resolveCategoryId(string $storeId, ProductInput $input): ?string
    {
        if ($input->categoryId !== null && $input->categoryName !== null) {
            // Two answers to one question. Guessing which the caller meant
            // would silently file a product under the wrong category.
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'Send either category_id or category_name, not both',
                [['field' => 'category_name', 'message' => 'Chỉ được chọn một cách xác định danh mục.']],
            );
        }

        if ($input->categoryId !== null) {
            // Scoped lookup: a category id from another store must read as
            // absent rather than as a usable reference.
            $category = $this->categories->findInStore($storeId, $input->categoryId);

            if ($category === null) {
                throw new BusinessRuleViolation(
                    ErrorCode::ValidationFailed,
                    'That category does not exist in this store',
                    [['field' => 'category_id', 'message' => 'Danh mục không thuộc cửa hàng này.']],
                );
            }

            return $category->id;
        }

        if ($input->categoryName === null || trim($input->categoryName) === '') {
            return null;
        }

        return $this->categories->findOrCreateByName($storeId, $input->categoryName)->id;
    }

    /**
     * Slugs are derived from the name unless the caller sent one.
     *
     * Vietnamese names slug to very little on their own - "Bánh mì" would
     * become "banh-mi", and two different dishes often collapse to the same
     * stem - so a collision gets a short suffix rather than a rejection: a slug
     * is a URL detail, and failing a save over one would be absurd.
     */
    private function resolveSlug(string $storeId, ?string $productId, string $name, ?string $provided): string
    {
        $base = $provided !== null && trim($provided) !== ''
            ? Str::slug($provided)
            : Str::slug($name);

        if ($base === '') {
            $base = 'san-pham';
        }

        $candidate = $base;
        $attempt = 1;

        while ($this->products->liveSlugExists($storeId, $candidate, $productId)) {
            $candidate = $base.'-'.(++$attempt);
        }

        return Str::limit($candidate, 180, '');
    }
}
