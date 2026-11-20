<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Action;

use App\Modules\Catalog\Application\DTO\ProductInput;
use App\Modules\Catalog\Application\ProductDraftFactory;
use App\Modules\Catalog\Domain\Contract\ProductRepository;
use App\Modules\Catalog\Domain\Entity\Product;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;
use Illuminate\Support\Facades\DB;

/**
 * Creates a product and its variants.
 *
 * Creating a product puts nothing on sale: a menu decides where, when and at
 * what price (CLAUDE.md section 6). That separation is what keeps the model
 * usable for packaged goods and retail, which have no menu at all.
 *
 * Exposed as a use case rather than as controller code so the same validated
 * operation can later be called by an import or an AI tool (section 11).
 */
final readonly class CreateProduct
{
    public function __construct(
        private ProductRepository $products,
        private ProductDraftFactory $drafts,
        private RecordsAuditTrail $audit,
    ) {}

    public function handle(string $storeId, ProductInput $input): Product
    {
        // The duplicate-name check and the insert have to see the same state,
        // or two requests a millisecond apart both pass the check. The partial
        // unique index is the backstop; the transaction is what makes the
        // check mean anything (CLAUDE.md section 16).
        return DB::transaction(function () use ($storeId, $input): Product {
            $product = $this->products->create($this->drafts->build($storeId, null, $input));

            $this->audit->record(
                AuditEntry::for('catalog.product.create', 'product', (string) $product->id, [
                    'name' => $product->name,
                    'status' => $product->status->value,
                    'variants' => count($product->variants),
                ])->inStore($storeId),
            );

            return $product;
        });
    }
}
