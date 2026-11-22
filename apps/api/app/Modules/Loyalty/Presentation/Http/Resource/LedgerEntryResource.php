<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Http\Resource;

use App\Modules\Loyalty\Domain\Entity\LedgerEntry;
use App\Shared\Presentation\Http\IsoTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `LoyaltyLedgerEntry` schema: one line of a member's history.
 *
 * `reward_name` and `actor_name` are resolved names, not raw ids - the
 * history screen has nothing else to join against, and a deleted-but-kept
 * reward (CLAUDE.md section 7 keeps financial-adjacent history, which is also
 * why the migration's foreign keys restrict rather than cascade) still shows
 * the name it had.
 *
 * @property-read LedgerEntry $resource
 */
final class LedgerEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            'type' => $entry->type->value,
            'points' => $entry->points,
            'payment_id' => $entry->paymentId,
            'reward_id' => $entry->rewardId,
            'reward_name' => $entry->rewardName,
            'note' => $entry->note,
            'actor_name' => $entry->actorName,
            'created_at' => IsoTimestamp::of($entry->createdAt),
        ];
    }
}
