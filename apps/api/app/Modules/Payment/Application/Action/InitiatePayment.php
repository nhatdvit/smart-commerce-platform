<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Action;

use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Modules\Payment\Domain\Enum\PaymentStatus;
use App\Modules\Payment\Infrastructure\Persistence\Model\Payment as PaymentModel;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;

/**
 * Opens the payment row a brand-new order is settled against.
 *
 * The use case checkout calls right after creating the order itself
 * (CLAUDE.md section 6: "generate a dynamic VietQR" happens only once a
 * `PENDING` payment exists to attach it to - see
 * {@see GenerateBankTransferInstructions}, which this hands off to for a
 * `BANK_TRANSFER` order). Kept separate from that action because this one
 * has no row to lock yet: there is nothing to confirm or regenerate, only to
 * open.
 *
 * Exists so `Order\Application\Action\CreateOrder` never imports
 * `Payment\Infrastructure` directly - the one allowed direction between
 * these two modules is through Payment's own exported Application layer
 * (CLAUDE.md section 3; enforced by `tests/Unit/ArchitectureTest.php`).
 */
final readonly class InitiatePayment
{
    public function handle(
        string $storeId,
        string $orderId,
        string $orderCode,
        string $customerName,
        PaymentMethod $method,
        Money $amount,
        ?DateTimeImmutable $expiresAt,
    ): string {
        /** @var PaymentModel $payment */
        $payment = PaymentModel::query()->create([
            'store_id' => $storeId,
            'order_id' => $orderId,
            'order_code' => $orderCode,
            'customer_name' => $customerName,
            'method' => $method->value,
            'status' => PaymentStatus::Pending->value,
            'amount' => $amount->amount,
            'currency' => $amount->currency,
            'expires_at' => $expiresAt,
        ]);

        return (string) $payment->id;
    }
}
