<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence\Model;

use App\Modules\Payment\Domain\Enum\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Row mapper for `payment_events`. Append only, like `order_status_history`:
 * no `updated_at`, written once and never changed.
 *
 * @property string $id
 * @property string $store_id
 * @property string $payment_id
 * @property string $event_type
 * @property PaymentStatus|null $from_status
 * @property PaymentStatus|null $to_status
 * @property string|null $actor_id
 * @property string|null $note
 * @property \Carbon\CarbonImmutable $created_at
 */
final class PaymentEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'payment_events';

    protected $fillable = [
        'store_id',
        'payment_id',
        'event_type',
        'from_status',
        'to_status',
        'actor_id',
        'note',
        'created_at',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_status' => PaymentStatus::class,
            'to_status' => PaymentStatus::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
