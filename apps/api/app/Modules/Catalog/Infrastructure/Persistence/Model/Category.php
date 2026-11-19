<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Row mapper for `categories`. Not the domain model (CLAUDE.md section 16).
 *
 * @property string $id
 * @property string $store_id
 * @property string|null $parent_id
 * @property string $name
 * @property string $name_key
 * @property string $slug
 * @property int $sort_order
 */
final class Category extends Model
{
    /** @use HasFactory<\Database\Factories\CategoryFactory> */
    use HasFactory, HasUuids;

    protected $table = 'categories';

    protected $fillable = ['store_id', 'parent_id', 'name', 'name_key', 'slug', 'sort_order'];

    /** UUIDv7 keys are time-ordered, which keeps index locality on insert. */
    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
