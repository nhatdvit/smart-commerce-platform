<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Modules\Payment\Domain\Enum\PaymentStatus;
use App\Modules\Payment\Infrastructure\Persistence\Model\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

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
            // Overwritten by on(); present so a bare create() in a test that
            // does not care about the order still satisfies the NOT NULL.
            'order_code' => sprintf('SCP-%s-%s', now()->format('Ymd'), Str::upper(Str::random(6))),
            'method' => PaymentMethod::Cash,
            'status' => PaymentStatus::Pending,
            'amount' => 0,
            'currency' => 'VND',
            'expires_at' => null,
            'paid_at' => null,
        ];
    }

    /**
     * order, store and the order's own code are one decision: the composite
     * foreign key requires the first two, and `order_code` is a SNAPSHOT of
     * the third that has to actually match it for a test to mean what it says
     * (see the note on the settlement migration).
     */
    public function on(string $orderId, string $storeId, string $orderCode, int $amount): static
    {
        return $this->state(fn (): array => [
            'order_id' => $orderId,
            'store_id' => $storeId,
            'order_code' => $orderCode,
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
     * A transfer with its dynamic VietQR already generated, the way checkout
     * will leave it - a reference and a payload, still `PENDING`.
     */
    public function withBankInstructions(
        string $bankAccountId,
        string $bankBin = '970436',
        string $bankAccountNumber = '0011001122334',
        string $bankAccountName = 'HO KINH DOANH BEP NHA MINH',
        ?string $transferReference = null,
    ): static {
        return $this->transfer()->state(fn (): array => [
            'bank_account_id' => $bankAccountId,
            'bank_bin' => $bankBin,
            'bank_account_number' => $bankAccountNumber,
            'bank_account_name' => $bankAccountName,
            'transfer_reference' => $transferReference ?? Str::upper(Str::random(12)),
            'qr_payload' => '00020101021138'.Str::random(40),
        ]);
    }

    /**
     * Money that staff have actually seen.
     *
     * `confirmed_by` is required rather than defaulted: the CHECK constraints
     * on `payments` refuse a `PAID` row with no named confirmer, and a factory
     * state that silently invented one would hide exactly the invariant this
     * whole feature exists to enforce (CLAUDE.md section 6).
     */
    public function paid(string $confirmedBy, ?int $confirmedAmount = null): static
    {
        return $this->state(function (array $attributes) use ($confirmedBy, $confirmedAmount): array {
            $at = now()->toImmutable();

            return [
                'status' => PaymentStatus::Paid,
                'paid_at' => $at,
                'confirmed_by' => $confirmedBy,
                'confirmed_at' => $at,
                'confirmed_amount' => $confirmedAmount ?? $attributes['amount'],
            ];
        });
    }
}
