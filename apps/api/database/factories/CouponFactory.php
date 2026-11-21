<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Pricing\Domain\Enum\CouponStatus;
use App\Modules\Pricing\Domain\Enum\DiscountType;
use App\Modules\Pricing\Infrastructure\Persistence\Model\Coupon;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Coupon>
 */
final class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    /**
     * A plain 5.000d off, running, unlimited.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            // Unique per store, and upper-case: the CHECK constraint refuses
            // anything else.
            'code' => 'GIAM'.Str::upper(Str::random(5)),
            'description' => null,
            'discount_type' => DiscountType::FixedAmount,
            'discount_value' => 5_000,
            'max_discount_amount' => null,
            'min_order_amount' => 50_000,
            'usage_limit' => null,
            'redeemed_count' => 0,
            'starts_at' => null,
            'ends_at' => null,
            'status' => CouponStatus::Active,
            'currency' => 'VND',
        ];
    }

    public function inStore(string $storeId): static
    {
        return $this->state(fn (): array => ['store_id' => $storeId]);
    }

    public function code(string $code): static
    {
        return $this->state(fn (): array => ['code' => strtoupper($code)]);
    }

    /** A percentage discount, optionally capped. */
    public function percentage(int $percent = 10, ?int $cap = null): static
    {
        return $this->state(fn (): array => [
            'discount_type' => DiscountType::Percentage,
            'discount_value' => $percent,
            'max_discount_amount' => $cap,
        ]);
    }

    /** A limit and the redemptions already counted against it. */
    public function used(int $redeemed, ?int $limit = null): static
    {
        return $this->state(fn (): array => [
            'usage_limit' => $limit,
            'redeemed_count' => $redeemed,
        ]);
    }

    public function status(CouponStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /** A window in the past, so the coupon reads as expired. */
    public function endedYesterday(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->subDays(7)->toImmutable(),
            'ends_at' => now()->subDay()->toImmutable(),
        ]);
    }

    /** A window that has not opened yet. */
    public function startsTomorrow(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->addDay()->toImmutable(),
            'ends_at' => now()->addDays(8)->toImmutable(),
        ]);
    }
}
