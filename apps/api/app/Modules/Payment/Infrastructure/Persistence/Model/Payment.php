<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence\Model;

use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Modules\Payment\Domain\Enum\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Row mapper for `payments`.
 *
 * A persistence mapper, not the domain model: the rules about who may move a
 * payment to PAID live in the module's application layer, which this class
 * knows nothing about (CLAUDE.md section 16).
 *
 * @property string $id
 * @property string $store_id
 * @property string $order_id
 * @property PaymentMethod $method
 * @property PaymentStatus $status
 * @property int $amount
 * @property string $currency
 * @property \Carbon\CarbonImmutable|null $expires_at
 * @property \Carbon\CarbonImmutable|null $paid_at
 */
final class Payment extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentFactory> */
    use HasFactory, HasUuids;

    protected $table = 'payments';

    protected $fillable = [
        'store_id',
        'order_id',
        'method',
        'status',
        'amount',
        'currency',
        'expires_at',
        'paid_at',
    ];

    /** Time-ordered keys, so "newest first" is an index scan on the primary key. */
    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            // Whole dong as an integer; never a float (CLAUDE.md section 6).
            'amount' => 'integer',
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
