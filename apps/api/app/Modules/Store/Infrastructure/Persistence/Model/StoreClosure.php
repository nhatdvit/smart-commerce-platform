<?php

declare(strict_types=1);

namespace App\Modules\Store\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Holidays and ad-hoc closures. These take priority over recurring hours.
 *
 * @property string $id
 * @property string $store_id
 * @property \Carbon\CarbonImmutable $start_at
 * @property \Carbon\CarbonImmutable $end_at
 * @property string|null $reason
 */
final class StoreClosure extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'store_closures';

    protected $fillable = ['store_id', 'start_at', 'end_at', 'reason'];

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
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
