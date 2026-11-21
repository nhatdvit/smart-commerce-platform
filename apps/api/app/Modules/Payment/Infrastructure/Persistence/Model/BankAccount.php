<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Row mapper for `store_bank_accounts`.
 *
 * @property string $id
 * @property string $store_id
 * @property string $bank_bin
 * @property string $bank_name
 * @property string $account_number
 * @property string $account_name
 * @property bool $is_default
 * @property bool $is_active
 * @property string|null $updated_by
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
final class BankAccount extends Model
{
    /** @use HasFactory<\Database\Factories\BankAccountFactory> */
    use HasFactory, HasUuids;

    protected $table = 'store_bank_accounts';

    protected $fillable = [
        'store_id',
        'bank_bin',
        'bank_name',
        'account_number',
        'account_name',
        'is_default',
        'is_active',
        'updated_by',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
