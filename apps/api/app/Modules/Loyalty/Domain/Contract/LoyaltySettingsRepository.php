<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Contract;

use App\Modules\Loyalty\Domain\ValueObject\LoyaltySettings;

/** Persistence seam for a store's earning policy. */
interface LoyaltySettingsRepository
{
    /** Never null - {@see LoyaltySettings::default()} stands in for a store that has not configured this yet. */
    public function forStore(string $storeId): LoyaltySettings;

    public function save(LoyaltySettings $settings): LoyaltySettings;
}
