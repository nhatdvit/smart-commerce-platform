<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Infrastructure\Persistence\Model;

use App\Modules\Loyalty\Domain\Enum\EntryType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Row mapper for `loyalty_ledger_entries`. One source of truth for a phone's
 * balance - see the migration note on why a balance is a plain SUM.
 *
 * @property string $id
 * @property string $store_id
 * @property string $phone
 * @property EntryType $type
 * @property int $points
 * @property string|null $payment_id
 * @property string|null $reward_id
 * @property string|null $note
 * @property string|null $actor_id
 * @property \Carbon\CarbonImmutable $created_at
 */
final class LedgerEntryModel extends Model
{
    use HasUuids;

    /** No `updated_at`: an entry is written once and never edited (CLAUDE.md section 6). */
    public const ?string UPDATED_AT = null;

    protected $table = 'loyalty_ledger_entries';

    protected $fillable = ['store_id', 'phone', 'type', 'points', 'payment_id', 'reward_id', 'note', 'actor_id'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return BelongsTo<RewardModel, $this> */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(RewardModel::class, 'reward_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => EntryType::class,
            'points' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
