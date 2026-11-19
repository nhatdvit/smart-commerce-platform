<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Persistence\Model;

use App\Modules\Catalog\Domain\Enum\CatalogStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Row mapper for `product_variants`.
 *
 * `base_price_amount` is BIGINT whole dong and is read as an int, never a
 * float (CLAUDE.md section 6).
 *
 * @property string $id
 * @property string $store_id
 * @property string $product_id
 * @property string|null $sku
 * @property string $name
 * @property string $name_key
 * @property int $base_price_amount
 * @property string $currency
 * @property CatalogStatus $status
 * @property int $sort_order
 */
final class ProductVariant extends Model
{
    /** @use HasFactory<\Database\Factories\ProductVariantFactory> */
    use HasFactory, HasUuids;

    protected $table = 'product_variants';

    protected $fillable = [
        'store_id',
        'product_id',
        'sku',
        'name',
        'name_key',
        'base_price_amount',
        'currency',
        'status',
        'sort_order',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Integer, so arithmetic stays exact. A float cast here would
            // reintroduce the rounding bug the money rules exist to prevent.
            'base_price_amount' => 'integer',
            'status' => CatalogStatus::class,
            'sort_order' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
