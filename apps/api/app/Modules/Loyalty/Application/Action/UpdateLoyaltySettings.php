<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Action;

use App\Modules\Loyalty\Domain\Contract\LoyaltySettingsRepository;
use App\Modules\Loyalty\Domain\ValueObject\LoyaltySettings;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;

/** Changes how many points a PAID order earns, and the loyal-tier threshold. */
final readonly class UpdateLoyaltySettings
{
    public function __construct(
        private LoyaltySettingsRepository $settings,
        private RecordsAuditTrail $audit,
    ) {}

    public function handle(
        string $storeId,
        int $pointsPerCurrency,
        int $loyalTierThresholdPoints,
        string $actorId,
    ): LoyaltySettings {
        $existing = $this->settings->forStore($storeId);

        $saved = $this->settings->save(
            LoyaltySettings::create($storeId, $pointsPerCurrency, $loyalTierThresholdPoints),
        );

        $this->audit->record(
            AuditEntry::for('loyalty.settings.update', 'store', $storeId, [
                'points_per_currency' => ['from' => $existing->pointsPerCurrency, 'to' => $saved->pointsPerCurrency],
                'loyal_tier_threshold_points' => [
                    'from' => $existing->loyalTierThresholdPoints,
                    'to' => $saved->loyalTierThresholdPoints,
                ],
            ])->inStore($storeId)->by($actorId),
        );

        return $saved;
    }
}
