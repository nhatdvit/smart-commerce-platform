<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Presentation\Http\Resource;

use App\Modules\Pricing\Domain\Entity\Coupon;
use App\Shared\Presentation\Http\IsoTimestamp;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `Coupon` schema.
 *
 * `availability` is computed here from the clock, not read from a column: a
 * code that ends at 10:00 is expired at 10:00 whether or not anything ran.
 * The client renders the label; it never derives the state itself, because
 * then two screens could disagree about what "hết hạn" means.
 *
 * `redeemed_count` is 0 for every coupon until checkout starts counting
 * redemptions, and the contract says so rather than letting a client read the
 * zero as "nobody wanted it".
 *
 * @property-read Coupon $resource
 */
final class CouponResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $coupon = $this->resource;
        $availability = $coupon->availabilityAt(new DateTimeImmutable);

        return [
            'id' => $coupon->id,
            'store_id' => $coupon->storeId,
            'code' => $coupon->code,
            'description' => $coupon->description,
            'discount_type' => $coupon->discountType->value,
            'discount_value' => $coupon->discountValue,
            'max_discount_amount' => $coupon->maxDiscount?->jsonSerialize(),
            'min_order_amount' => $coupon->minOrder->jsonSerialize(),
            'usage_limit' => $coupon->usageLimit,
            'redeemed_count' => $coupon->redeemedCount,
            'remaining_uses' => $coupon->remainingUses(),
            'starts_at' => IsoTimestamp::of($coupon->startsAt),
            'ends_at' => IsoTimestamp::of($coupon->endsAt),
            'status' => $coupon->status->value,
            'availability' => $availability->value,
            'created_at' => IsoTimestamp::of($coupon->createdAt),
        ];
    }
}
