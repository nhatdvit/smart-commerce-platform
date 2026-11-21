<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure\Persistence\Model;

use App\Modules\Pricing\Domain\Enum\CouponStatus;
use App\Modules\Pricing\Domain\Enum\DiscountType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Row mapper for `coupons`.
 *
 * A persistence mapper, not the domain model: what makes a discount valid
 * lives in `Domain\Entity\Coupon` and knows nothing about Eloquent
 * (CLAUDE.md section 16).
 *
 * @property string $id
 * @property string $store_id
 * @property string $code
 * @property string|null $description
 * @property DiscountType $discount_type
 * @property int $discount_value
 * @property int|null $max_discount_amount
 * @property int $min_order_amount
 * @property int|null $usage_limit
 * @property int $redeemed_count
 * @property \Carbon\CarbonImmutable|null $starts_at
 * @property \Carbon\CarbonImmutable|null $ends_at
 * @property CouponStatus $status
 * @property string $currency
 * @property \Carbon\CarbonImmutable|null $created_at
 */
final class Coupon extends Model
{
    /** @use HasFactory<\Database\Factories\CouponFactory> */
    use HasFactory, HasUuids;

    protected $table = 'coupons';

    protected $fillable = [
        'store_id',
        'code',
        'description',
        'discount_type',
        'discount_value',
        'max_discount_amount',
        'min_order_amount',
        'usage_limit',
        'redeemed_count',
        'starts_at',
        'ends_at',
        'status',
        'currency',
    ];

    /** Time-ordered keys, so "newest first" is an index scan on the key. */
    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'status' => CouponStatus::class,
            // Whole dong or whole percent; never a float (CLAUDE.md section 6).
            'discount_value' => 'integer',
            'max_discount_amount' => 'integer',
            'min_order_amount' => 'integer',
            'usage_limit' => 'integer',
            'redeemed_count' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
