<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Domain\Enum\UserStatus;
use App\Modules\Identity\Infrastructure\Persistence\Model\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /** Hashing once and reusing it keeps test suites from burning CPU on bcrypt. */
    private static ?string $passwordHash = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'password_hash' => self::$passwordHash ??= Hash::make('password'),
            'status' => UserStatus::Active,
        ];
    }

    public function blocked(): static
    {
        return $this->state(fn (): array => ['status' => UserStatus::Blocked]);
    }

    /** A customer who registered by phone rather than email. */
    public function phoneOnly(): static
    {
        return $this->state(fn (): array => [
            'email' => null,
            'phone' => '09'.fake()->numerify('########'),
        ]);
    }
}
