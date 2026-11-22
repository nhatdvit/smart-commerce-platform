<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Entity;

use App\Modules\Loyalty\Domain\Enum\EntryType;
use DateTimeImmutable;

/**
 * One row of a phone's history: a payment that earned points, or a reward
 * that spent them. Immutable and never edited - a correction is a new,
 * recorded entry, the same convention order status history and audit
 * entries follow (CLAUDE.md section 6).
 */
final readonly class LedgerEntry
{
    public function __construct(
        public string $id,
        public EntryType $type,
        /** Signed: positive for EARN, negative for REDEEM. */
        public int $points,
        public ?string $paymentId,
        public ?string $rewardId,
        public ?string $rewardName,
        public ?string $note,
        public ?string $actorName,
        public DateTimeImmutable $createdAt,
    ) {}
}
