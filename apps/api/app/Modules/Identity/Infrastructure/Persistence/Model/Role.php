<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence\Model;

use App\Modules\Identity\Domain\Enum\RoleScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * One of SUPER_ADMIN, STORE_OWNER, STORE_MANAGER, CASHIER, KITCHEN_STAFF.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property RoleScope $scope
 */
final class Role extends Model
{
    use HasUuids;

    protected $table = 'roles';

    protected $fillable = ['code', 'name', 'scope', 'description'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scope' => RoleScope::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
