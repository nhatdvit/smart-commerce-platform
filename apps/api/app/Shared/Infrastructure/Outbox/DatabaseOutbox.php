<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Application\Contract\RecordsDomainEvents;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Persistence\Model\OutboxEvent;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Writes domain events to the `outbox_events` table.
 *
 * Deliberately has no transaction of its own: it joins the caller one, which
 * is what makes the guarantee work.
 *
 * The connection is injected rather than reached for through the DB facade so
 * that the "must be inside a transaction" rule can be exercised by a test. A
 * test suite wrapped in a transaction of its own can never observe level 0,
 * and an invariant nothing can check is an invariant that quietly rots. It is
 * the same connection the model writes on, so the level it reports is the
 * level the insert will run at.
 */
final class DatabaseOutbox implements RecordsDomainEvents
{
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function record(OutboxMessage $message): void
    {
        // Writing an event outside a transaction defeats the pattern: it would
        // commit on its own and could describe a change that never happened.
        // Failing loudly here beats shipping a subtly broken guarantee.
        if ($this->connection->transactionLevel() === 0) {
            throw new RuntimeException(sprintf(
                'Outbox event "%s" must be recorded inside a database transaction, '
                .'together with the state change it describes.',
                $message->eventType,
            ));
        }

        OutboxEvent::query()->create([
            'aggregate_type' => $message->aggregateType,
            'aggregate_id' => $message->aggregateId,
            'event_type' => $message->eventType,
            'payload' => $message->payload,
            'status' => OutboxEvent::STATUS_PENDING,
        ]);
    }
}
