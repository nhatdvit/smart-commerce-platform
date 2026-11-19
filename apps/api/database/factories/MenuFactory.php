<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Menu\Domain\Enum\MenuStatus;
use App\Modules\Menu\Infrastructure\Persistence\Model\Menu;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use App\Shared\Domain\ValueObject\DisplayName;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Menu>
 */
final class MenuFactory extends Factory
{
    protected $model = Menu::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            // Unique per store, case-insensitively, so the suffix matters.
            'name' => 'Thực đơn '.Str::lower(Str::random(5)),
            'status' => MenuStatus::Active,
        ];
    }

    /** The comparison key is derived after overrides, never passed in. */
    public function configure(): static
    {
        return $this->afterMaking(static function (Menu $menu): void {
            $menu->name_key = DisplayName::key($menu->name);
        });
    }

    public function inStore(string $storeId): static
    {
        return $this->state(fn (): array => ['store_id' => $storeId]);
    }
}
