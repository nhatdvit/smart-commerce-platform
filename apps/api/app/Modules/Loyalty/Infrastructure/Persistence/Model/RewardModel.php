<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Row mapper for `loyalty_rewards`.
 *
 * @property string $id
 * @property string $store_id
 * @property string $name
 * @property int $points_cost
 * @property string|null $icon
 * @property bool $is_active
 * @property-read int|null $redemptions_count set only when loaded via withCount('redemptions')
 */
final class RewardModel extends Model
{
    use HasUuids;

    protected $table = 'loyalty_rewards';

    protected $fillable = ['store_id', 'name', 'points_cost', 'icon', 'is_active'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return HasMany<LedgerEntryModel, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(LedgerEntryModel::class, 'reward_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'points_cost' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
