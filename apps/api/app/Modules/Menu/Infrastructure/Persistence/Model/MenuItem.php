<?php

declare(strict_types=1);

namespace App\Modules\Menu\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Row mapper for `menu_items`.
 *
 * Soft-deleted by hand rather than with Laravel's SoftDeletes trait: removal
 * here is a business event ("taken off the menu"), and the repository is the
 * only thing that may decide a removed row is invisible. A global scope would
 * also silently hide rows from a future reconciliation or report that has every
 * reason to see them.
 *
 * @property string $id
 * @property string $store_id
 * @property string $menu_id
 * @property string $product_variant_id
 * @property int $selling_price_amount
 * @property string $currency
 * @property bool $is_available
 * @property int|null $available_quantity
 * @property int|null $daily_capacity
 * @property int $sort_order
 * @property \Carbon\CarbonImmutable|null $deleted_at
 */
final class MenuItem extends Model
{
    /** @use HasFactory<\Database\Factories\MenuItemFactory> */
    use HasFactory, HasUuids;

    protected $table = 'menu_items';

    protected $fillable = [
        'store_id',
        'menu_id',
        'product_variant_id',
        'selling_price_amount',
        'currency',
        'is_available',
        'available_quantity',
        'daily_capacity',
        'sort_order',
        'deleted_at',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * Items still on the menu.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeListed(Builder $query): Builder
    {
        return $query->whereNull('deleted_at');
    }

    /** @return BelongsTo<Menu, $this> */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Whole dong as an integer; never a float (CLAUDE.md section 6).
            'selling_price_amount' => 'integer',
            'is_available' => 'boolean',
            'available_quantity' => 'integer',
            'daily_capacity' => 'integer',
            'sort_order' => 'integer',
            'deleted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
