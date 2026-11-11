<?php

declare(strict_types=1);

use App\Shared\Application\Contract\RecordsDomainEvents;
use App\Shared\Application\Outbox\OutboxMessage;
use App\Shared\Infrastructure\Outbox\DatabaseOutbox;
use App\Shared\Infrastructure\Outbox\DispatchPendingOutboxEvents;
use App\Shared\Infrastructure\Outbox\PublishOutboxEvent;
use App\Shared\Infrastructure\Persistence\Model\OutboxEvent;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * The transactional outbox is what stops a committed order from losing its
 * event, and an event from describing an order that rolled back (§6).
 */

function recordEvent(string $type = 'order.placed', array $payload = ['order_id' => 'abc']): void
{
    DB::transaction(function () use ($type, $payload): void {
        app(RecordsDomainEvents::class)->record(
            OutboxMessage::for('order', 'order-1', $type, $payload),
        );
    });
}

it('records an event inside a transaction', function (): void {
    recordEvent();

    $event = OutboxEvent::query()->sole();

    expect($event->event_type)->toBe('order.placed')
        ->and($event->status)->toBe(OutboxEvent::STATUS_PENDING)
        ->and($event->payload)->toBe(['order_id' => 'abc'])
        ->and($event->published_at)->toBeNull();
});

it('refuses to record outside a transaction, because the guarantee would be fake', function (): void {
    // RefreshDatabase holds a transaction for the whole test, so the real
    // connection can never report level 0 here. The rule still deserves a
    // test, so the connection is what gets faked rather than the rule
    // getting weakened to suit the harness.
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('transactionLevel')->andReturn(0);

    (new DatabaseOutbox($connection))->record(OutboxMessage::for('order', 'o1', 'order.placed'));
})->throws(RuntimeException::class, 'must be recorded inside a database transaction');

it('leaves no event behind when the business transaction rolls back', function (): void {
    try {
        DB::transaction(function (): void {
            app(RecordsDomainEvents::class)->record(
                OutboxMessage::for('order', 'order-9', 'order.placed'),
            );

            throw new RuntimeException('business rule failed after recording');
        });
    } catch (RuntimeException) {
        // expected
    }

    // This is the whole point: no event for a change that never happened.
    expect(OutboxEvent::query()->count())->toBe(0);
});

it('publishes pending events to the queue and marks them published', function (): void {
    Queue::fake();
    recordEvent();

    $result = (new DispatchPendingOutboxEvents)();

    expect($result['published'])->toBe(1);
    Queue::assertPushed(PublishOutboxEvent::class, 1);

    $event = OutboxEvent::query()->sole();
    expect($event->status)->toBe(OutboxEvent::STATUS_PUBLISHED)
        ->and($event->published_at)->not->toBeNull();
});

it('does not publish the same event twice', function (): void {
    Queue::fake();
    recordEvent();

    (new DispatchPendingOutboxEvents)();
    $second = (new DispatchPendingOutboxEvents)();

    expect($second['published'])->toBe(0);
    Queue::assertPushed(PublishOutboxEvent::class, 1);
});

it('publishes oldest first, so consumers see events in the order they happened', function (): void {
    Queue::fake();

    DB::transaction(function (): void {
        $outbox = app(RecordsDomainEvents::class);
        $outbox->record(OutboxMessage::for('order', 'o1', 'order.placed'));
        $outbox->record(OutboxMessage::for('order', 'o1', 'payment.confirmed'));
    });

    // Force a distinguishable ordering regardless of clock resolution.
    OutboxEvent::query()->where('event_type', 'order.placed')
        ->update(['created_at' => now()->subMinute()]);

    (new DispatchPendingOutboxEvents)();

    $types = [];
    Queue::assertPushed(PublishOutboxEvent::class, function (PublishOutboxEvent $job) use (&$types): bool {
        $types[] = (new ReflectionProperty($job, 'eventType'))->getValue($job);

        return true;
    });

    expect($types)->toBe(['order.placed', 'payment.confirmed']);
});

it('keeps an event pending and counts the attempt when publishing fails', function (): void {
    recordEvent();

    // A queue connection that always throws stands in for a broker outage.
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('queue down'));

    $result = (new DispatchPendingOutboxEvents)();

    $event = OutboxEvent::query()->sole();

    expect($result['published'])->toBe(0)
        // Still PENDING: a transient outage must not lose the event.
        ->and($event->status)->toBe(OutboxEvent::STATUS_PENDING)
        ->and($event->attempts)->toBe(1)
        ->and($event->last_error)->toContain('queue down');
});

it('gives up after the attempt limit rather than retrying for ever', function (): void {
    recordEvent();
    OutboxEvent::query()->update(['attempts' => 4]);

    Queue::shouldReceive('connection')->andThrow(new RuntimeException('queue down'));

    $result = (new DispatchPendingOutboxEvents)();

    expect($result['failed'])->toBe(1)
        ->and(OutboxEvent::query()->sole()->status)->toBe(OutboxEvent::STATUS_FAILED);
});
