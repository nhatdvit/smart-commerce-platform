<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence\Model;

use App\Modules\Payment\Domain\Enum\DiscrepancyType;
use App\Modules\Payment\Domain\Enum\OverpaymentDisposition;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Row mapper for `payment_discrepancies`. Append only: there is deliberately
 * no "resolved" column for the same reason there is no resolve endpoint - see
 * the domain entity's own note.
 *
 * @property string $id
 * @property string $store_id
 * @property string $payment_id
 * @property DiscrepancyType $type
 * @property int|null $expected_amount
 * @property int|null $observed_amount
 * @property OverpaymentDisposition|null $disposition
 * @property string|null $note
 * @property string|null $recorded_by
 * @property \Carbon\CarbonImmutable $recorded_at
 */
final class PaymentDiscrepancy extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'payment_discrepancies';

    protected $fillable = [
        'store_id',
        'payment_id',
        'type',
        'expected_amount',
        'observed_amount',
        'disposition',
        'note',
        'recorded_by',
        'recorded_at',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => DiscrepancyType::class,
            'expected_amount' => 'integer',
            'observed_amount' => 'integer',
            'disposition' => OverpaymentDisposition::class,
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
