<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence\Model;

use App\Modules\Identity\Domain\Enum\UserStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * Staff and registered customers.
 *
 * A guest order does NOT create a user. Guest access is a scoped token on the
 * order itself (CLAUDE.md §6).
 *
 * This is a persistence model, not the domain model: it maps rows. Business
 * rules belong in Domain and Application (CLAUDE.md §16).
 *
 * @property string $id
 * @property string $full_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $password_hash
 * @property UserStatus $status
 */
final class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasUuids;

    protected $table = 'users';

    protected $fillable = [
        'full_name',
        'email',
        'phone',
        'status',
    ];

    /**
     * Never serialised, whatever the caller asks for.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
    ];

    /** UUIDv7 keys are time-ordered, which keeps index locality on insert. */
    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** Laravel looks for a "password" attribute; this schema calls it password_hash. */
    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    /** @return HasMany<StoreMember, $this> */
    public function storeMemberships(): HasMany
    {
        return $this->hasMany(StoreMember::class);
    }

    /**
     * Platform-wide roles only. Store-scoped roles come from store_members.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function globalRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => UserStatus::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
