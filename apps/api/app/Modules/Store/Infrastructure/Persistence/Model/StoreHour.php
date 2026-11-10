<?php

declare(strict_types=1);

namespace App\Modules\Store\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Recurring opening hours in store-local time.
 *
 * day_of_week follows ISO-8601: 1 is Monday through 7 is Sunday. A database
 * CHECK constraint enforces that range, so a bad write fails at the source.
 *
 * @property string $id
 * @property string $store_id
 * @property int $day_of_week
 * @property string $open_time
 * @property string $close_time
 * @property bool $is_closed
 */
final class StoreHour extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'store_hours';

    protected $fillable = ['store_id', 'day_of_week', 'open_time', 'close_time', 'is_closed'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'is_closed' => 'boolean',
        ];
    }
}
