<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Payment\Infrastructure\Persistence\Model\BankAccount;
use App\Modules\Store\Infrastructure\Persistence\Model\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
final class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    /**
     * Vietcombank, active and default - a plausible store configuration.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'bank_bin' => '970436',
            'bank_name' => 'Vietcombank',
            'account_number' => (string) $this->faker->numerify('##########'),
            'account_name' => 'HO KINH DOANH BEP NHA MINH',
            'is_default' => true,
            'is_active' => true,
            'updated_by' => null,
        ];
    }

    public function inStore(string $storeId): static
    {
        return $this->state(fn (): array => ['store_id' => $storeId]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false, 'is_default' => false]);
    }

    public function updatedBy(string $userId): static
    {
        return $this->state(fn (): array => ['updated_by' => $userId]);
    }
}
