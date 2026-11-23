<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Resource;

use App\Modules\Payment\Domain\Entity\BankAccount;
use App\Modules\Payment\Domain\Entity\Payment;
use App\Modules\Payment\Domain\Entity\PaymentDiscrepancy;
use App\Modules\Payment\Domain\Entity\PaymentEvent;
use App\Shared\Presentation\Http\IsoTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `AdminPayment` schema from the designed contract.
 *
 * The same shape serves both the reconciliation list and the detail read: the
 * design defines one `AdminPayment` type and an `AdminPaymentPage` of them,
 * not a separate summary. `events` and `discrepancies` are simply empty on a
 * list row, which the repository does not pay to populate there.
 *
 * `bank_account_masked` is derived here from the snapshot on the payment, not
 * from the store's current bank account - the beneficiary a customer actually
 * paid against must read the same way today as the day the QR was shown, even
 * if the store has since changed accounts (CLAUDE.md section 6).
 *
 * @property-read Payment $resource
 */
final class AdminPaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $payment = $this->resource;

        return [
            'id' => $payment->id,
            'order_id' => $payment->orderId,
            'order_code' => $payment->orderCode,
            'customer_name' => $payment->customerName,
            'store_id' => $payment->storeId,
            'method' => $payment->method->value,
            'status' => $payment->status->value,
            'amount' => $payment->amount->jsonSerialize(),
            'confirmed_amount' => $payment->confirmedAmount?->jsonSerialize(),
            'transfer_reference' => $payment->transferReference,
            // The raw EMVCo string, for the admin screen to render its own
            // scannable QR from - never a system an operator needs to trust;
            // they can read bank_account_masked/transfer_reference instead.
            'qr_payload' => $payment->qrPayload,
            'bank_transaction_reference' => $payment->bankTransactionReference,
            'bank_account_masked' => $payment->bankAccountNumber === null
                ? null
                : BankAccount::mask($payment->bankAccountNumber),
            'confirmed_by' => $payment->confirmedByName,
            'confirmed_at' => IsoTimestamp::of($payment->confirmedAt),
            'expires_at' => IsoTimestamp::of($payment->expiresAt),
            'needs_attention' => $payment->needsAttention(),
            'discrepancies' => array_values(array_map(
                static fn (PaymentDiscrepancy $discrepancy): array => [
                    'type' => $discrepancy->type->value,
                    'expected_amount' => $discrepancy->expectedAmount?->jsonSerialize(),
                    'observed_amount' => $discrepancy->observedAmount?->jsonSerialize(),
                    'note' => $discrepancy->note,
                    'recorded_by' => $discrepancy->recordedByName,
                    'recorded_at' => IsoTimestamp::of($discrepancy->recordedAt),
                ],
                $payment->discrepancies,
            )),
            'events' => array_values(array_map(
                static fn (PaymentEvent $event): array => [
                    'event_type' => $event->eventType,
                    'from_status' => $event->from?->value,
                    'to_status' => $event->to?->value,
                    'actor_name' => $event->actorName,
                    'created_at' => IsoTimestamp::of($event->at),
                ],
                $payment->events,
            )),
        ];
    }
}
