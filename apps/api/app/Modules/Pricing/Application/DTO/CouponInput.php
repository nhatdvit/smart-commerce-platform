<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Application\DTO;

use App\Modules\Pricing\Domain\Enum\CouponStatus;
use App\Modules\Pricing\Domain\Enum\DiscountType;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;

/**
 * A coupon as the operator described it, before the domain has judged it.
 *
 * `redeemedCount` is absent on purpose: how many times a code has been used is
 * the system's record, never something a form submits.
 */
final readonly class CouponInput
{
    public function __construct(
        public string $code,
        public ?string $description,
        public DiscountType $discountType,
        public int $discountValue,
        public ?Money $maxDiscount,
        public Money $minOrder,
        public ?int $usageLimit,
        public ?DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public CouponStatus $status,
    ) {}
}
