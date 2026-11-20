<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Action;

use App\Modules\Catalog\Application\DTO\ProductInput;
use App\Modules\Catalog\Application\ProductDraftFactory;
use App\Modules\Catalog\Domain\Contract\ProductRepository;
use App\Modules\Catalog\Domain\Entity\Product;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Updates a product, including retiring it.
 *
 * Renaming or repricing changes what is offered from now on; it never rewrites
 * an order, because order lines hold their own snapshot of name, variant and
 * price precisely so yesterday's receipt stays true (CLAUDE.md section 6).
 *
 * "Delete" arrives here as `status: ARCHIVED`. There is no hard delete in the
 * catalog: the row stays, its history stays readable, and a menu stops offering
 * it because a menu only lists listable products.
 */
final readonly class UpdateProduct
{
    public function __construct(
        private ProductRepository $products,
        private ProductDraftFactory $drafts,
        private RecordsAuditTrail $audit,
    ) {}

    public function handle(string $storeId, string $productId, ProductInput $input): Product
    {
        return DB::transaction(function () use ($storeId, $productId, $input): Product {
            $existing = $this->products->findInStore($storeId, $productId);

            if ($existing === null) {
                // Not "403 wrong store": whether a product exists elsewhere is
                // not information this store is entitled to (CLAUDE.md section 8).
                throw new BusinessRuleViolation(
                    ErrorCode::ResourceNotFound,
                    'Product not found',
                );
            }

            $product = $this->products->update(
                $productId,
                $this->drafts->build($storeId, $productId, $input),
            );

            $this->audit->record(
                AuditEntry::for('catalog.product.update', 'product', $productId, [
                    'name' => ['from' => $existing->name, 'to' => $product->name],
                    'status' => ['from' => $existing->status->value, 'to' => $product->status->value],
                ])->inStore($storeId),
            );

            return $product;
        });
    }
}
