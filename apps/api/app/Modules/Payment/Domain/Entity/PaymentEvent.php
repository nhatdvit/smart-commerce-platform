<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Entity;

use App\Modules\Payment\Domain\Enum\PaymentStatus;
use DateTimeImmutable;

/**
 * One recorded move of a payment through its own state machine.
 *
 * Payment status is a separate state machine from order status (CLAUDE.md
 * section 6), and it gets its own trail for the same reason an order does:
 * "who confirmed this, and when" is a question that gets asked about real
 * money, and the current status alone cannot answer it.
 */
final readonly class PaymentEvent
{
    public function __construct(
        /** `CONFIRMED`, `FLAGGED` - not always a status change; see Confirm/Flag. */
        public string $eventType,
        public ?PaymentStatus $from,
        public ?PaymentStatus $to,
        /** Null when the system recorded it rather than a person. */
        public ?string $actorId,
        public ?string $actorName,
        public ?string $note,
        public ?DateTimeImmutable $at,
    ) {}
}
