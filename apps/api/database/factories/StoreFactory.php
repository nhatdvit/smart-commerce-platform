<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Store\Domain\Enum\StoreStatus;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Store>
 */
final class StoreFactory extends Factory
{
    protected $model = Store::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'code' => Str::upper(Str::random(8)),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'phone' => '02'.fake()->numerify('########'),
            'address' => fake()->streetAddress(),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'status' => StoreStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => StoreStatus::Inactive]);
    }

    /** A store in a different timezone, for schedule-evaluation tests. */
    public function inTimezone(string $timezone): static
    {
        return $this->state(fn (): array => ['timezone' => $timezone]);
    }
}
