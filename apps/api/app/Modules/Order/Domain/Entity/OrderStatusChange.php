<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Entity;

use App\Modules\Order\Domain\Enum\OrderStatus;
use DateTimeImmutable;

/**
 * One recorded move of an order through its state machine.
 *
 * Every transition writes one of these (CLAUDE.md section 6). It is the answer
 * to "who cancelled this order, and why" - a question that gets asked about
 * real money and cannot be answered from the current status alone.
 *
 * `actorId` is null when the system made the move, for example a scheduled job
 * expiring an unpaid transfer. That is a real distinction, not a gap to fill
 * with a placeholder account.
 */
final readonly class OrderStatusChange
{
    public function __construct(
        /** Null on the row that records the order being created. */
        public ?OrderStatus $from,
        public OrderStatus $to,
        public ?string $actorId,
        /** Resolved for display; null for a system move or a deleted account. */
        public ?string $actorName,
        public ?string $reason,
        public ?DateTimeImmutable $at,
    ) {}
}
