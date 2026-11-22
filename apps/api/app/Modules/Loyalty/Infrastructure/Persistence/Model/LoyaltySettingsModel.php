<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * Row mapper for `loyalty_settings`. One row per store, keyed by `store_id`
 * itself rather than a surrogate id - see the migration note.
 *
 * @property string $store_id
 * @property int $points_per_currency
 * @property int $loyal_tier_threshold_points
 */
final class LoyaltySettingsModel extends Model
{
    public $incrementing = false;

    protected $table = 'loyalty_settings';

    protected $primaryKey = 'store_id';

    /** @var string */
    protected $keyType = 'string';

    protected $fillable = ['store_id', 'points_per_currency', 'loyal_tier_threshold_points'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'points_per_currency' => 'integer',
            'loyal_tier_threshold_points' => 'integer',
        ];
    }
}
