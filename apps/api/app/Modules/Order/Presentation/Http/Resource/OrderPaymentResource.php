<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Http\Resource;

use App\Modules\Order\Domain\Enum\FulfillmentType;
use App\Modules\Payment\Application\DTO\CustomerPaymentView;
use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Shared\Presentation\Http\IsoTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `OrderPayment` schema: everything a customer needs to pay their own
 * order, and everything staff need to recognise the credit afterwards
 * (CLAUDE.md section 6).
 *
 * Shaped here from Payment's exported {@see CustomerPaymentView}, the same
 * "the order endpoints own their own response shape" convention
 * {@see PaymentSummaryResource} already follows.
 *
 * A QR is not a receipt: `payment.status` stays `PENDING` until authorized
 * staff confirm the real bank credit, however this screen is rendered.
 *
 * @property-read CustomerPaymentView $resource
 */
final class OrderPaymentResource extends JsonResource
{
    public function __construct(CustomerPaymentView $payment, private readonly FulfillmentType $fulfillmentType)
    {
        parent::__construct($payment);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $payment = $this->resource;

        return [
            'payment' => [
                'id' => $payment->paymentId,
                'method' => $payment->method->value,
                'status' => $payment->status->value,
                'amount' => $payment->amount->jsonSerialize(),
                'expires_at' => IsoTimestamp::of($payment->expiresAt),
                'paid_at' => IsoTimestamp::of($payment->paidAt),
                'customer_reported_at' => IsoTimestamp::of($payment->customerReportedAt),
            ],
            'bank_transfer' => $payment->method === PaymentMethod::BankTransfer ? [
                'transfer_reference' => $payment->transferReference,
                'amount' => $payment->amount->jsonSerialize(),
                'bank' => [
                    'bin' => $payment->bankBin,
                    'name' => $payment->bankName,
                    'account_number' => $payment->bankAccountNumber,
                    'account_name' => $payment->bankAccountName,
                ],
                // Rendered client-side from the payload; no server-rendered
                // image exists in this slice.
                'qr_payload' => $payment->qrPayload,
                'qr_image_url' => null,
                'expires_at' => IsoTimestamp::of($payment->expiresAt),
            ] : null,
            'cash' => $payment->method === PaymentMethod::Cash ? [
                'collect_at' => $this->fulfillmentType === FulfillmentType::Delivery
                    ? 'ON_DELIVERY'
                    : 'PICKUP_COUNTER',
                'note' => null,
            ] : null,
        ];
    }
}
