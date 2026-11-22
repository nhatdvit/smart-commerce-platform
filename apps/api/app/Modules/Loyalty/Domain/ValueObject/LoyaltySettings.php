<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\ValueObject;

use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;

/**
 * A store's earning policy. One of these per store, defaulting rather than
 * requiring a row to exist: a store that has never touched this screen still
 * has a sensible policy, the same convention
 * {@see \App\Modules\Payment\Infrastructure\Persistence\EloquentPaymentMethodRepository}
 * applies to an unprovisioned payment method.
 */
final readonly class LoyaltySettings
{
    public const int DEFAULT_POINTS_PER_CURRENCY = 10_000;

    public const int DEFAULT_LOYAL_TIER_THRESHOLD_POINTS = 100;

    private function __construct(
        public string $storeId,
        /** Whole VND a customer spends, on a PAID order, to earn one point. */
        public int $pointsPerCurrency,
        public int $loyalTierThresholdPoints,
    ) {}

    public static function create(string $storeId, int $pointsPerCurrency, int $loyalTierThresholdPoints): self
    {
        if ($pointsPerCurrency <= 0) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'Points per currency must be positive',
                [['field' => 'points_per_currency', 'message' => 'Số tiền quy đổi phải lớn hơn 0.']],
            );
        }

        if ($loyalTierThresholdPoints < 0) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'The loyal-tier threshold cannot be negative',
                [['field' => 'loyal_tier_threshold_points', 'message' => 'Ngưỡng hạng thân thiết không được âm.']],
            );
        }

        return new self($storeId, $pointsPerCurrency, $loyalTierThresholdPoints);
    }

    public static function default(string $storeId): self
    {
        return new self($storeId, self::DEFAULT_POINTS_PER_CURRENCY, self::DEFAULT_LOYAL_TIER_THRESHOLD_POINTS);
    }

    /** Whole points a PAID order of this amount earns. Floored: a part point is not a point. */
    public function pointsFor(int $amountVnd): int
    {
        return intdiv(max(0, $amountVnd), $this->pointsPerCurrency);
    }
}
