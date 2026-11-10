<?php

declare(strict_types=1);

namespace App\Modules\Store\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-store switches. These carry business policy that is still open
 * (CLAUDE.md section 15), so they are configuration rather than hard-coded rules.
 *
 * @property string $store_id
 * @property bool $allow_guest_checkout
 * @property bool $pickup_enabled
 * @property bool $delivery_enabled
 * @property int $order_lead_minutes
 * @property string $currency
 */
final class StoreSetting extends Model
{
    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = null;

    public $incrementing = false;

    protected $table = 'store_settings';

    protected $primaryKey = 'store_id';

    protected $keyType = 'string';

    protected $fillable = [
        'store_id',
        'allow_guest_checkout',
        'pickup_enabled',
        'delivery_enabled',
        'order_lead_minutes',
        'currency',
    ];

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'allow_guest_checkout' => 'boolean',
            'pickup_enabled' => 'boolean',
            'delivery_enabled' => 'boolean',
            'order_lead_minutes' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
