<?php

declare(strict_types=1);

namespace App\Modules\Order\Infrastructure\Persistence\Model;

use App\Modules\Order\Domain\Enum\FulfillmentType;
use App\Modules\Order\Domain\Enum\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Row mapper for `orders`.
 *
 * A persistence mapper, not the domain model: the state machine lives in
 * `Domain\Entity\Order` and knows nothing about Eloquent (CLAUDE.md
 * section 16). This class exists to read and write columns.
 *
 * @property string $id
 * @property string $store_id
 * @property string $order_code
 * @property string|null $customer_id
 * @property string|null $guest_access_token_hash
 * @property string $contact_name
 * @property string $contact_phone
 * @property string|null $contact_email
 * @property OrderStatus $status
 * @property FulfillmentType $fulfillment_type
 * @property \Carbon\CarbonImmutable|null $scheduled_at
 * @property string|null $delivery_address_line
 * @property string|null $note
 * @property int $subtotal_amount
 * @property int $discount_amount
 * @property int $delivery_fee_amount
 * @property int $tax_amount
 * @property int $total_amount
 * @property string $currency
 * @property \Carbon\CarbonImmutable|null $placed_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Illuminate\Database\Eloquent\Collection<int, OrderItem> $items
 * @property \Illuminate\Database\Eloquent\Collection<int, OrderStatusChange> $statusChanges
 */
final class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory, HasUuids;

    protected $table = 'orders';

    protected $fillable = [
        'store_id',
        'order_code',
        'customer_id',
        'contact_name',
        'contact_phone',
        'contact_email',
        'status',
        'fulfillment_type',
        'scheduled_at',
        'delivery_address_line',
        'note',
        'subtotal_amount',
        'discount_amount',
        'delivery_fee_amount',
        'tax_amount',
        'total_amount',
        'currency',
        'placed_at',
    ];

    /**
     * Never mass-assigned, the same convention as `User::$password_hash`: it
     * is set once, deliberately, via `forceFill()` at checkout - never a
     * column a later update could silently overwrite.
     *
     * @var list<string>
     */
    protected $hidden = [
        'guest_access_token_hash',
    ];

    /**
     * UUIDv7, so the key is time-ordered.
     *
     * That is what lets the queue page over the primary key: "id ascending" is
     * "oldest first", with no separate sort column and no page that shifts
     * under a client while new orders arrive.
     */
    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderStatusChange, $this> */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(OrderStatusChange::class);
    }

    /**
     * Orders still in the queue - the five statuses staff can act on.
     *
     * Matches the partial index `idx_orders_open_queue`, so the scan stays
     * proportional to today's work rather than to everything ever served.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', array_values(array_map(
            static fn (OrderStatus $status): string => $status->value,
            array_filter(
                OrderStatus::cases(),
                static fn (OrderStatus $status): bool => $status->isOpen(),
            ),
        )));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'fulfillment_type' => FulfillmentType::class,
            // Whole dong as integers; never floats (CLAUDE.md section 6).
            'subtotal_amount' => 'integer',
            'discount_amount' => 'integer',
            'delivery_fee_amount' => 'integer',
            'tax_amount' => 'integer',
            'total_amount' => 'integer',
            'scheduled_at' => 'immutable_datetime',
            'placed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
