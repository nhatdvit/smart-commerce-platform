<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A single named capability, for example payment.confirm.
 *
 * @property string $id
 * @property string $code
 * @property string $description
 */
final class Permission extends Model
{
    use HasUuids;

    /** Reference data: created once by the seeder, never edited in place. */
    public $timestamps = false;

    protected $table = 'permissions';

    protected $fillable = ['code', 'description'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }
}
