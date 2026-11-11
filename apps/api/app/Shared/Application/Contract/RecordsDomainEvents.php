<?php

declare(strict_types=1);

namespace App\Shared\Application\Contract;

use App\Shared\Application\Outbox\OutboxMessage;

/**
 * Records a domain event for later publication.
 *
 * **Call this inside the same `DB::transaction()` as the business change.**
 * That is the whole point of the pattern (§6): the event row and the state it
 * describes commit together, so a committed order can never be missing its
 * event, and an event can never describe an order that rolled back.
 *
 * Laravel `dispatchAfterCommit()` is not a substitute. It fires after the
 * commit but keeps nothing durable, so a crash in the window between commit
 * and dispatch loses the event with no trace and no way to detect it (§16).
 */
interface RecordsDomainEvents
{
    public function record(OutboxMessage $message): void;
}
