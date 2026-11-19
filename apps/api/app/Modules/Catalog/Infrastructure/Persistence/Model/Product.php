<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Persistence\Model;

use App\Modules\Catalog\Domain\Enum\CatalogStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Row mapper for `products`.
 *
 * @property string $id
 * @property string $store_id
 * @property string|null $category_id
 * @property string $name
 * @property string $name_key
 * @property string $slug
 * @property string|null $description
 * @property CatalogStatus $status
 * @property bool $inventory_tracking
 * @property string|null $image_url
 * @property string|null $icon
 * @property array<string, mixed> $structured_attributes
 */
final class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory, HasUuids;

    protected $table = 'products';

    protected $fillable = [
        'store_id',
        'category_id',
        'name',
        'name_key',
        'slug',
        'description',
        'status',
        'inventory_tracking',
        'image_url',
        'icon',
        'structured_attributes',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * Every query starts store-scoped.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeInStore(Builder $query, string $storeId): Builder
    {
        return $query->where('store_id', $storeId);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CatalogStatus::class,
            'inventory_tracking' => 'boolean',
            // The column is jsonb; the domain reads it through ProductAttributes.
            'structured_attributes' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
