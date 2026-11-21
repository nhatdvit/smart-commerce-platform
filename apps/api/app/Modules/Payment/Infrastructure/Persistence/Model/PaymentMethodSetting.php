<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence\Model;

use App\Modules\Payment\Domain\Enum\PaymentMethod;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Row mapper for `payment_methods`: one row per store per MVP method.
 *
 * @property string $id
 * @property string $store_id
 * @property PaymentMethod $method
 * @property bool $enabled
 */
final class PaymentMethodSetting extends Model
{
    use HasUuids;

    protected $table = 'payment_methods';

    protected $fillable = ['store_id', 'method', 'enabled'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'enabled' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
