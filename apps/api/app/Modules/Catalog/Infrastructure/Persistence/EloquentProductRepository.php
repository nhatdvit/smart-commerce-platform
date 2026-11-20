<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Persistence;

use App\Modules\Catalog\Domain\Contract\ProductRepository;
use App\Modules\Catalog\Domain\Entity\Category;
use App\Modules\Catalog\Domain\Entity\Product;
use App\Modules\Catalog\Domain\Entity\ProductVariant;
use App\Modules\Catalog\Domain\Enum\CatalogStatus;
use App\Modules\Catalog\Domain\ValueObject\ProductAttributes;
use App\Modules\Catalog\Infrastructure\Persistence\Model\Product as ProductModel;
use App\Modules\Catalog\Infrastructure\Persistence\Model\ProductVariant as VariantModel;
use App\Shared\Domain\ValueObject\DisplayName;
use App\Shared\Domain\ValueObject\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent implementation of the product seam.
 *
 * This is the only class in the module that knows the catalog tables exist, and
 * it is where rows become domain objects (CLAUDE.md section 16). Everything it
 * returns is already store-scoped: there is no method here that can read across
 * stores, by construction rather than by remembering to add a where clause.
 */
final class EloquentProductRepository implements ProductRepository
{
    /** Hard ceiling on a page, whatever the client asks for (CLAUDE.md section 8). */
    public const int MAX_LIMIT = 100;

    public function findInStore(string $storeId, string $productId): ?Product
    {
        $row = $this->baseQuery($storeId)->whereKey($productId)->first();

        return $row === null ? null : $this->toEntity($row);
    }

    public function liveNameExists(string $storeId, string $name, ?string $exceptProductId = null): bool
    {
        // Compared on the stored key, which is the same key the unique index
        // uses, so this check and the constraint can never disagree.
        return $this->liveQuery($storeId, $exceptProductId)
            ->where('name_key', DisplayName::key($name))
            ->exists();
    }

    public function liveSlugExists(string $storeId, string $slug, ?string $exceptProductId = null): bool
    {
        return $this->liveQuery($storeId, $exceptProductId)
            ->where('slug', $slug)
            ->exists();
    }

    public function paginate(
        string $storeId,
        array $statuses,
        ?string $search,
        ?string $cursor,
        int $limit,
    ): array {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $query = $this->baseQuery($storeId);

        if ($statuses !== []) {
            $query->whereIn('status', array_map(
                static fn (CatalogStatus $status): string => $status->value,
                $statuses,
            ));
        }

        if ($search !== null && trim($search) !== '') {
            $needle = '%'.trim($search).'%';
            $query->where(function (Builder $scoped) use ($needle): void {
                $scoped->where('name', 'ilike', $needle)
                    ->orWhere('description', 'ilike', $needle);
            });
        }

        // Cursor pagination over the primary key, not an offset: UUIDv7 keys are
        // time-ordered, so "id descending" is "newest first" and a page cannot
        // shift under a client that is inserting rows (CLAUDE.md section 8).
        $after = $this->decodeCursor($cursor);

        if ($after !== null) {
            $query->where('id', '<', $after);
        }

        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $page = $hasMore ? $rows->take($limit) : $rows;
        $last = $page->last();

        return [
            'items' => array_values($page->map(fn (ProductModel $row): Product => $this->toEntity($row))->all()),
            'next_cursor' => $hasMore && $last instanceof ProductModel ? $this->encodeCursor($last->id) : null,
            'has_more' => $hasMore,
        ];
    }

    public function imageUrlsInUse(string $storeId, array $imageUrls): array
    {
        if ($imageUrls === []) {
            return [];
        }

        /** @var list<string> $inUse */
        $inUse = ProductModel::query()
            ->where('store_id', $storeId)
            ->whereIn('image_url', $imageUrls)
            ->pluck('image_url')
            ->all();

        return array_values(array_unique($inUse));
    }

    public function create(Product $product): Product
    {
        $row = ProductModel::query()->create($this->columns($product));

        foreach ($product->variants as $index => $variant) {
            VariantModel::query()->create($this->variantColumns($row, $variant, $index));
        }

        return $this->toEntity($this->baseQuery($product->storeId)->whereKey($row->id)->sole());
    }

    public function update(string $productId, Product $product): Product
    {
        $row = $this->baseQuery($product->storeId)->whereKey($productId)->sole();
        $row->fill($this->columns($product))->save();

        /** @var array<string, VariantModel> $existing */
        $existing = $row->variants->keyBy('id')->all();
        $kept = [];

        foreach ($product->variants as $index => $variant) {
            $current = $variant->id !== null ? ($existing[$variant->id] ?? null) : null;

            if ($current === null) {
                $created = VariantModel::query()->create($this->variantColumns($row, $variant, $index));
                $kept[$created->id] = true;

                continue;
            }

            $current->fill($this->variantColumns($row, $variant, $index))->save();
            $kept[$current->id] = true;
        }

        foreach ($existing as $id => $variantRow) {
            if (isset($kept[$id]) || $variantRow->status === CatalogStatus::Archived) {
                continue;
            }

            // Archived, never deleted: a variant can be referenced by a menu
            // item or by an order line, and removing the row would take a
            // receipt's meaning with it (CLAUDE.md section 6).
            $variantRow->status = CatalogStatus::Archived;
            $variantRow->save();
        }

        return $this->toEntity($this->baseQuery($product->storeId)->whereKey($productId)->sole());
    }

    /**
     * Products with everything a response needs, store-scoped.
     *
     * The category and variants are eager-loaded because strict mode turns a
     * lazy load into an exception - which is the point: an N+1 on a list
     * endpoint should fail in development, not in production traffic.
     *
     * @return Builder<ProductModel>
     */
    private function baseQuery(string $storeId): Builder
    {
        return ProductModel::query()
            ->where('store_id', $storeId)
            ->with(['category', 'variants' => static fn ($query) => $query->orderBy('sort_order')->orderBy('name')]);
    }

    /** @return Builder<ProductModel> */
    private function liveQuery(string $storeId, ?string $exceptProductId): Builder
    {
        $query = ProductModel::query()
            ->where('store_id', $storeId)
            ->where('status', '<>', CatalogStatus::Archived->value);

        if ($exceptProductId !== null) {
            $query->whereKeyNot($exceptProductId);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function columns(Product $product): array
    {
        return [
            'store_id' => $product->storeId,
            'category_id' => $product->categoryId,
            'name' => $product->name,
            'name_key' => DisplayName::key($product->name),
            'slug' => $product->slug,
            'description' => $product->description,
            'status' => $product->status->value,
            'inventory_tracking' => $product->inventoryTracking,
            'image_url' => $product->imageUrl,
            'icon' => $product->icon,
            'structured_attributes' => $product->attributes->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    private function variantColumns(ProductModel $product, ProductVariant $variant, int $index): array
    {
        return [
            'store_id' => $product->store_id,
            'product_id' => $product->id,
            'sku' => $variant->sku,
            'name' => $variant->name,
            'name_key' => DisplayName::key($variant->name),
            'base_price_amount' => $variant->basePrice->amount,
            'currency' => $variant->basePrice->currency,
            'status' => $variant->status->value,
            'sort_order' => $variant->sortOrder !== 0 ? $variant->sortOrder : $index,
        ];
    }

    private function toEntity(ProductModel $row): Product
    {
        $category = $row->category;

        return Product::create(
            $row->id,
            $row->store_id,
            $row->category_id,
            $row->name,
            $row->slug,
            $row->description,
            $row->status,
            $row->inventory_tracking,
            $row->image_url,
            $row->icon,
            ProductAttributes::fromArray($row->structured_attributes),
            array_values($row->variants
                ->map(static fn (VariantModel $variant): ProductVariant => ProductVariant::create(
                    $variant->id,
                    $variant->sku,
                    $variant->name,
                    Money::vnd($variant->base_price_amount),
                    $variant->status,
                    $variant->sort_order,
                ))
                ->all()),
            $category === null ? null : new Category(
                $category->id,
                $category->store_id,
                $category->name,
                $category->slug,
                $category->sort_order,
            ),
        );
    }

    /** Opaque to the client: a cursor is a position, not a value to construct. */
    private function encodeCursor(string $id): string
    {
        return rtrim(strtr(base64_encode($id), '+/', '-_'), '=');
    }

    private function decodeCursor(?string $cursor): ?string
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        // A malformed cursor reads as "no cursor" rather than an error: it is
        // either a client bug or a stale link, and neither deserves a 500.
        return is_string($decoded) && preg_match('/^[0-9a-f-]{36}$/i', $decoded) === 1
            ? $decoded
            : null;
    }
}
