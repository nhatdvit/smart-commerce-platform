<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Http\Resource;

use App\Modules\Order\Domain\Entity\CustomerSummary;
use App\Shared\Presentation\Http\IsoTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `AdminCustomer` schema: a guest's order history, grouped by phone.
 *
 * Deliberately has no points, tier or membership field - that is Loyalty's
 * data, which needs a real customer identity and a real checkout to mean
 * anything (CLAUDE.md delivery stage 4). This resource is order history only.
 *
 * @property-read CustomerSummary $resource
 */
final class CustomerSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $customer = $this->resource;

        return [
            'phone' => $customer->phone,
            'name' => $customer->name,
            'email' => $customer->email,
            'order_count' => $customer->orderCount,
            'total_spent' => $customer->totalSpent->jsonSerialize(),
            'last_order_at' => IsoTimestamp::of($customer->lastOrderAt),
        ];
    }
}
