<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Http\Resource;

use App\Modules\Loyalty\Domain\Entity\LoyaltyMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `LoyaltyMember` schema: a phone's standing in the program.
 *
 * `tier_label` rides along in Vietnamese because that is the only language
 * this screen renders in today - the same choice `CouponResource` makes by
 * leaving rendering to the client, except here the label has no other
 * sensible machine form than the tier code already carries.
 *
 * @property-read LoyaltyMember $resource
 */
final class LoyaltyMemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $member = $this->resource;

        return [
            'phone' => $member->phone,
            'name' => $member->name,
            'lifetime_earned_points' => $member->lifetimeEarnedPoints,
            'balance' => $member->balance,
            'tier' => $member->tier->value,
            'tier_label' => $member->tier->label(),
        ];
    }
}
