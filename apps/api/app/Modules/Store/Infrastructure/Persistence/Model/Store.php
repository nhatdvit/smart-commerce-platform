<?php

declare(strict_types=1);

namespace App\Modules\Store\Infrastructure\Persistence\Model;

use App\Modules\Store\Domain\Enum\StoreStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A selling location.
 *
 * The timezone is per store and is the only correct basis for evaluating
 * opening hours, menu schedules and daily quotas. Timestamps are stored in UTC
 * (CLAUDE.md section 6).
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property StoreStatus $status
 */
final class Store extends Model
{
    /** @use HasFactory<\Database\Factories\StoreFactory> */
    use HasFactory, HasUuids;

    protected $table = 'stores';

    protected $fillable = ['code', 'name', 'slug', 'phone', 'address', 'timezone', 'status'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return HasOne<StoreSetting, $this> */
    public function settings(): HasOne
    {
        return $this->hasOne(StoreSetting::class);
    }

    /** @return HasMany<StoreHour, $this> */
    public function hours(): HasMany
    {
        return $this->hasMany(StoreHour::class);
    }

    /** @return HasMany<StoreClosure, $this> */
    public function closures(): HasMany
    {
        return $this->hasMany(StoreClosure::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => StoreStatus::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
