<?php

declare(strict_types=1);

namespace App\Shared\Application\Outbox;

/**
 * A domain event on its way out.
 *
 * Plain data so a module can build one without the framework, and so the
 * payload is obviously serialisable - it has to survive a round trip through
 * the database and the queue.
 *
 * `eventType` follows `aggregate.verb-past-tense`: `order.placed`,
 * `payment.confirmed`, `stock.reservation-expired`. Consumers match on it, so
 * renaming one is a breaking change for every listener.
 */
final readonly class OutboxMessage
{
    /**
     * @param  array<array-key, mixed>  $payload  Must be JSON-serialisable. Never put a model here:
     *                                            the consumer may run minutes later, when the row has changed.
     *                                            Put identifiers and the facts that mattered at the time.
     */
    public function __construct(
        public string $aggregateType,
        public string $aggregateId,
        public string $eventType,
        public array $payload = [],
    ) {}

    /** @param array<array-key, mixed> $payload */
    public static function for(
        string $aggregateType,
        string $aggregateId,
        string $eventType,
        array $payload = [],
    ): self {
        return new self($aggregateType, $aggregateId, $eventType, $payload);
    }
}
