<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Http\Resource;

use App\Modules\Payment\Application\DTO\PaymentSnapshot;
use App\Shared\Presentation\Http\IsoTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `PaymentSummary` schema, as an order response carries it.
 *
 * Shaped here, in the Order module, from Payment's exported snapshot: the
 * order endpoints own their own response shape, and the Payment module will
 * shape its own screens' responses (CLAUDE.md section 3).
 *
 * `status` is the payment's own, never inferred from the order's. An order can
 * be COMPLETED with this field still `PENDING`, and that is exactly what an
 * uncollected cash order looks like (section 6).
 *
 * `paid_at` is when staff confirmed the real money - not when a QR was shown
 * and not when a customer said they had transferred.
 *
 * @property-read PaymentSnapshot $resource
 */
final class PaymentSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $payment = $this->resource;

        return [
            'id' => $payment->paymentId,
            'method' => $payment->method->value,
            'status' => $payment->status->value,
            'amount' => $payment->amount->jsonSerialize(),
            'expires_at' => IsoTimestamp::of($payment->expiresAt),
            'paid_at' => IsoTimestamp::of($payment->paidAt),
        ];
    }
}
