<?php

declare(strict_types=1);

namespace App\Modules\Menu\Infrastructure\Persistence\Model;

use App\Modules\Menu\Domain\Enum\MenuStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Row mapper for `menus`.
 *
 * @property string $id
 * @property string $store_id
 * @property string $name
 * @property string $name_key
 * @property MenuStatus $status
 */
final class Menu extends Model
{
    /** @use HasFactory<\Database\Factories\MenuFactory> */
    use HasFactory, HasUuids;

    protected $table = 'menus';

    protected $fillable = ['store_id', 'name', 'name_key', 'status'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return HasMany<MenuItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => MenuStatus::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
