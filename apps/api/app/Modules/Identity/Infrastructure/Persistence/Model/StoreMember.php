<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence\Model;

use App\Modules\Identity\Domain\Enum\MembershipStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A staff membership of one store, carrying the store-scoped role.
 *
 * Membership belongs to Identity (CLAUDE.md §5); the Store module owns the store
 * itself. Authorization reads both sides: the role must grant the permission
 * AND an active membership must exist for that store.
 *
 * @property string $id
 * @property string $store_id
 * @property string $user_id
 * @property string $role_id
 * @property MembershipStatus $status
 */
final class StoreMember extends Model
{
    use HasUuids;

    protected $table = 'store_members';

    protected $fillable = ['store_id', 'user_id', 'role_id', 'status'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
