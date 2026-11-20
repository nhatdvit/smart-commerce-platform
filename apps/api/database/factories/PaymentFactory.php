<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Modules\Payment\Domain\Enum\PaymentStatus;
use App\Modules\Payment\Infrastructure\Persistence\Model\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
final class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * Cash, not yet collected - the ordinary state of a breakfast order.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'method' => PaymentMethod::Cash,
            'status' => PaymentStatus::Pending,
            'amount' => 0,
            'currency' => 'VND',
            'expires_at' => null,
            'paid_at' => null,
        ];
    }

    /** order and store are one decision: the composite foreign key requires it. */
    public function on(string $orderId, string $storeId, int $amount): static
    {
        return $this->state(fn (): array => [
            'order_id' => $orderId,
            'store_id' => $storeId,
            'amount' => $amount,
        ]);
    }

    /** A bank transfer, with the window the store gives a customer to pay in. */
    public function transfer(?int $expiresInMinutes = null): static
    {
        $expiresInMinutes ??= (int) config('commerce.bank_transfer.expiry_minutes', 30);

        return $this->state(fn (): array => [
            'method' => PaymentMethod::BankTransfer,
            'expires_at' => now()->addMinutes($expiresInMinutes)->toImmutable(),
        ]);
    }

    /**
     * Money that staff have actually seen.
     *
     * `paid_at` comes with the status because the database requires it: a
     * payment that claims to be paid has to say when the money was verified
     * (CLAUDE.md section 6).
     */
    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Paid,
            'paid_at' => now()->toImmutable(),
        ]);
    }
}
