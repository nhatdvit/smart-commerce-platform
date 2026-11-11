<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Application\Outbox\OutboxMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Carries one outbox event onto the queue, where the worker fires
 * OutboxEventPublished for the module listeners.
 *
 * The job holds plain data, not a model: by the time it runs, the row it came
 * from may have changed, and the event must describe what was true when it was
 * recorded.
 */
final class PublishOutboxEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Bounded retries with exponential backoff (§10). After this the job goes
     * to the failed table, where it can be inspected and replayed rather than
     * disappearing.
     */
    public int $tries = 5;

    /** @var list<int> seconds between attempts */
    public array $backoff = [10, 30, 60, 300];

    public function __construct(
        private readonly string $outboxEventId,
        private readonly string $aggregateType,
        private readonly string $aggregateId,
        private readonly string $eventType,
        /** @var array<array-key, mixed> */
        private readonly array $payload,
    ) {}

    public function handle(): void
    {
        OutboxEventPublished::dispatch(
            $this->outboxEventId,
            new OutboxMessage(
                $this->aggregateType,
                $this->aggregateId,
                $this->eventType,
                $this->payload,
            ),
        );
    }

    /**
     * Groups retries and failures by event type in Horizon.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return ['outbox', $this->eventType];
    }
}
