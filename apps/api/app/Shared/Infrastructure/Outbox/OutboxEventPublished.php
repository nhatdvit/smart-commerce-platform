<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Application\Outbox\OutboxMessage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once per outbox row that reaches the queue.
 *
 * Modules subscribe to this instead of to each other, which keeps them from
 * depending on one another directly (§3): Notification can react to
 * `order.placed` without Order knowing Notification exists.
 *
 * **Listeners must be idempotent.** Delivery is at-least-once by design — see
 * DispatchPendingOutboxEvents for why duplicates are preferred to losses — so
 * the same event will occasionally arrive twice.
 */
final class OutboxEventPublished
{
    use Dispatchable;

    public function __construct(
        public readonly string $outboxEventId,
        public readonly OutboxMessage $message,
    ) {}
}
