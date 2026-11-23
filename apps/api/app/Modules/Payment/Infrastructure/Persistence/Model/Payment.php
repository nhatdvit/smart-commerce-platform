<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence\Model;

use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Modules\Payment\Domain\Enum\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Row mapper for `payments`.
 *
 * A persistence mapper, not the domain model: the rules about who may move a
 * payment to PAID live in the module's application layer, which this class
 * knows nothing about (CLAUDE.md section 16).
 *
 * `order_code` is a snapshot copied from `orders` at write time, never a join;
 * see the note on the settlement migration for why.
 *
 * @property string $id
 * @property string $store_id
 * @property string $order_id
 * @property string $order_code
 * @property string $customer_name
 * @property PaymentMethod $method
 * @property PaymentStatus $status
 * @property int $amount
 * @property string $currency
 * @property string|null $transfer_reference
 * @property string|null $qr_payload
 * @property string|null $bank_account_id
 * @property string|null $bank_bin
 * @property string|null $bank_account_number
 * @property string|null $bank_account_name
 * @property int|null $confirmed_amount
 * @property string|null $bank_transaction_reference
 * @property string|null $confirmed_by
 * @property \Carbon\CarbonImmutable|null $confirmed_at
 * @property \Carbon\CarbonImmutable|null $expires_at
 * @property \Carbon\CarbonImmutable|null $paid_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Illuminate\Database\Eloquent\Collection<int, PaymentEvent> $paymentEvents
 * @property \Illuminate\Database\Eloquent\Collection<int, PaymentDiscrepancy> $discrepancies
 */
final class Payment extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentFactory> */
    use HasFactory, HasUuids;

    protected $table = 'payments';

    protected $fillable = [
        'store_id',
        'order_id',
        'order_code',
        'customer_name',
        'method',
        'status',
        'amount',
        'currency',
        'expires_at',
        'paid_at',
        'transfer_reference',
        'qr_payload',
        'bank_account_id',
        'bank_bin',
        'bank_account_number',
        'bank_account_name',
        'confirmed_amount',
        'bank_transaction_reference',
        'confirmed_by',
        'confirmed_at',
    ];

    /** Time-ordered keys, so "newest first" is an index scan on the primary key. */
    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * Named `paymentEvents`, not `events`: Eloquent models already expose a
     * static `events` property/method on the base class, and shadowing it
     * would be exactly the surprise CLAUDE.md section 12 asks code to avoid.
     *
     * @return HasMany<PaymentEvent, $this>
     */
    public function paymentEvents(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    /** @return HasMany<PaymentDiscrepancy, $this> */
    public function discrepancies(): HasMany
    {
        return $this->hasMany(PaymentDiscrepancy::class);
    }

    /**
     * This store's payments still needing a human: outstanding, or carrying a
     * recorded discrepancy. Matches the domain entity's own `needsAttention()`
     * so the two can never disagree about what the queue shows.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->where(function (Builder $scoped): void {
            $scoped->whereIn('status', ['PENDING', 'PROCESSING'])
                ->orWhereExists(static function ($discrepancies): void {
                    $discrepancies->selectRaw('1')
                        ->from('payment_discrepancies')
                        ->whereColumn('payment_discrepancies.payment_id', 'payments.id');
                });
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            // Whole dong as an integer; never a float (CLAUDE.md section 6).
            'amount' => 'integer',
            'confirmed_amount' => 'integer',
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
