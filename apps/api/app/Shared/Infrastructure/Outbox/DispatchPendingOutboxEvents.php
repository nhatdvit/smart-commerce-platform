<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Infrastructure\Persistence\Model\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Moves pending outbox rows onto the queue.
 *
 * Run from the scheduler, one instance at a time.
 *
 * ## Why duplicates are preferred to losses
 *
 * Each row is queued **before** it is marked published. If the process dies in
 * between, the row stays `PENDING` and is picked up again, so the event is
 * delivered twice. The reverse order would mark it published first and lose it
 * entirely on the same crash.
 *
 * A duplicate is recoverable — §6 requires consumers to be idempotent, so the
 * second delivery is a no-op. A lost event is not recoverable and, worse, is
 * invisible: nothing in the system says an order notification never went out.
 *
 * ## Why rows are locked
 *
 * `lockForUpdate()->skipLocked()` means a second dispatcher takes different
 * rows rather than blocking or double-publishing. Only one scheduler should
 * run (§16), but a stuck deploy or a manual run should not corrupt anything.
 */
final class DispatchPendingOutboxEvents
{
    public function __construct(
        private readonly int $batchSize = 100,
        private readonly int $maxAttempts = 5,
    ) {}

    /** @return array{published: int, failed: int} */
    public function __invoke(): array
    {
        $published = 0;
        $failed = 0;

        foreach ($this->claimBatch() as $id) {
            $result = $this->publishOne($id);

            if ($result === true) {
                $published++;
            } elseif ($result === false) {
                $failed++;
            }
        }

        return ['published' => $published, 'failed' => $failed];
    }

    /**
     * Read the ids first, in their own short transaction, so the long work of
     * queueing does not hold locks on the whole batch.
     *
     * @return list<string>
     */
    private function claimBatch(): array
    {
        return array_values(OutboxEvent::query()
            ->where('status', OutboxEvent::STATUS_PENDING)
            // Oldest first: order of events within an aggregate is the order
            // they happened, and a consumer that sees "paid" before "placed"
            // has no way to make sense of it.
            ->orderBy('created_at')
            ->limit($this->batchSize)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all());
    }

    /**
     * true published, false not published this round (may retry later),
     * null skipped because another dispatcher holds it.
     */
    private function publishOne(string $id): ?bool
    {
        return DB::transaction(function () use ($id): ?bool {
            /** @var OutboxEvent|null $event */
            $event = OutboxEvent::query()
                ->whereKey($id)
                ->where('status', OutboxEvent::STATUS_PENDING)
                // Raw lock clause rather than lockForUpdate()->skipLocked():
                // SKIP LOCKED is what makes a second dispatcher take other
                // rows instead of blocking behind this one.
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();

            // Another dispatcher took it, or it is no longer pending.
            if ($event === null) {
                return null;
            }

            try {
                PublishOutboxEvent::dispatch(
                    $event->id,
                    $event->aggregate_type,
                    $event->aggregate_id,
                    $event->event_type,
                    $event->payload,
                );

                $event->forceFill([
                    'status' => OutboxEvent::STATUS_PUBLISHED,
                    'published_at' => now(),
                ])->save();

                return true;
            } catch (Throwable $e) {
                $this->recordFailure($event, $e);

                // Not published this round, whether it will be retried or has
                // just run out of attempts. Returning the exhausted flag from
                // recordFailure() here counted an exhausted event as published,
                // because true means published to the caller.
                return false;
            }
        });
    }

    /**
     * Records the attempt and decides whether this event is out of them.
     *
     * Returns nothing on purpose: the caller has three outcomes - published,
     * not published and skipped - and exhausted is not one of them. It is
     * recorded on the row and in the log instead.
     */
    private function recordFailure(OutboxEvent $event, Throwable $e): void
    {
        $attempts = $event->attempts + 1;
        $exhausted = $attempts >= $this->maxAttempts;

        $event->forceFill([
            'attempts' => $attempts,
            // The class and message are useful for diagnosis; the payload is
            // not repeated here because it is already in the row.
            'last_error' => mb_substr($e::class.': '.$e->getMessage(), 0, 2000),
            // Stays PENDING until attempts run out, so transient failures
            // retry on the next scheduler tick.
            'status' => $exhausted ? OutboxEvent::STATUS_FAILED : OutboxEvent::STATUS_PENDING,
        ])->save();

        Log::error('Failed to publish outbox event', [
            'outbox_event_id' => $event->id,
            'event_type' => $event->event_type,
            'attempts' => $attempts,
            'exhausted' => $exhausted,
        ]);
    }
}
