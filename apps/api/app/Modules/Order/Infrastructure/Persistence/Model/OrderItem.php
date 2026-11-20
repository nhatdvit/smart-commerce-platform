<?php

declare(strict_types=1);

namespace App\Modules\Order\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Row mapper for `order_items`.
 *
 * Every name and amount in this row is a snapshot taken at checkout. There is
 * deliberately no relation to the product or variant for reading the name
 * from: joining the catalogue would make yesterday's receipt change when a
 * dish is renamed, which is precisely what CLAUDE.md section 6 forbids. The
 * ids are kept for reporting only.
 *
 * @property string $id
 * @property string $store_id
 * @property string $order_id
 * @property string|null $product_id
 * @property string|null $product_variant_id
 * @property string $product_name
 * @property string $variant_name
 * @property int $quantity
 * @property int $unit_price_amount
 * @property int $modifiers_total_amount
 * @property int $line_total_amount
 * @property string $currency
 * @property string|null $note
 * @property int $sort_order
 */
final class OrderItem extends Model
{
    /** @use HasFactory<\Database\Factories\OrderItemFactory> */
    use HasFactory, HasUuids;

    protected $table = 'order_items';

    protected $fillable = [
        'store_id',
        'order_id',
        'product_id',
        'product_variant_id',
        'product_name',
        'variant_name',
        'quantity',
        'unit_price_amount',
        'modifiers_total_amount',
        'line_total_amount',
        'currency',
        'note',
        'sort_order',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_amount' => 'integer',
            'modifiers_total_amount' => 'integer',
            'line_total_amount' => 'integer',
            'sort_order' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
