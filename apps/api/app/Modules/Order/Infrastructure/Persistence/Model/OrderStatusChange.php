<?php

declare(strict_types=1);

namespace App\Modules\Order\Infrastructure\Persistence\Model;

use App\Modules\Order\Domain\Enum\OrderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Row mapper for `order_status_history`.
 *
 * Append only. A row here is the record that someone moved an order, and
 * rewriting or deleting one would destroy the only answer to "who cancelled
 * this, and why" (CLAUDE.md sections 6 and 7) - which is why the table has no
 * `updated_at` and this model writes `created_at` itself rather than carrying
 * Eloquent timestamps.
 *
 * @property string $id
 * @property string $store_id
 * @property string $order_id
 * @property OrderStatus|null $from_status
 * @property OrderStatus $to_status
 * @property string|null $actor_id
 * @property string|null $reason
 * @property \Carbon\CarbonImmutable $created_at
 */
final class OrderStatusChange extends Model
{
    use HasUuids;

    /** No `updated_at` on an append-only table. */
    public $timestamps = false;

    protected $table = 'order_status_history';

    protected $fillable = [
        'store_id',
        'order_id',
        'from_status',
        'to_status',
        'actor_id',
        'reason',
        'created_at',
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
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
