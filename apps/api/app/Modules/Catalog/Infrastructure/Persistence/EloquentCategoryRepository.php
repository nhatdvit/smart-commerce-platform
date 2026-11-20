<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Persistence;

use App\Modules\Catalog\Domain\Contract\CategoryRepository;
use App\Modules\Catalog\Domain\Entity\Category;
use App\Modules\Catalog\Infrastructure\Persistence\Model\Category as CategoryModel;
use App\Shared\Domain\ValueObject\DisplayName;
use Illuminate\Support\Str;

/** Eloquent implementation of the category seam. */
final class EloquentCategoryRepository implements CategoryRepository
{
    public function findInStore(string $storeId, string $categoryId): ?Category
    {
        $row = CategoryModel::query()
            ->where('store_id', $storeId)
            ->whereKey($categoryId)
            ->first();

        return $row === null ? null : $this->toEntity($row);
    }

    public function findOrCreateByName(string $storeId, string $name): Category
    {
        $name = DisplayName::normalise($name);

        // Matched on the stored key, which is exactly what the unique index
        // uses, so the lookup and the constraint cannot disagree.
        $existing = CategoryModel::query()
            ->where('store_id', $storeId)
            ->where('name_key', DisplayName::key($name))
            ->first();

        if ($existing !== null) {
            return $this->toEntity($existing);
        }

        $row = CategoryModel::query()->create([
            'store_id' => $storeId,
            'name' => $name,
            'name_key' => DisplayName::key($name),
            'slug' => $this->uniqueSlug($storeId, $name),
            'sort_order' => (int) CategoryModel::query()->where('store_id', $storeId)->max('sort_order') + 1,
        ]);

        return $this->toEntity($row);
    }

    /**
     * Vietnamese names slug to short stems and collide often, so a clash gets a
     * numeric suffix rather than an error: a slug is a URL detail and is never
     * worth failing a save over.
     */
    private function uniqueSlug(string $storeId, string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'danh-muc';
        }

        $candidate = $base;
        $attempt = 1;

        while (CategoryModel::query()->where('store_id', $storeId)->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.(++$attempt);
        }

        return $candidate;
    }

    private function toEntity(CategoryModel $row): Category
    {
        return new Category(
            $row->id,
            $row->store_id,
            $row->name,
            $row->slug,
            $row->sort_order,
        );
    }
}
