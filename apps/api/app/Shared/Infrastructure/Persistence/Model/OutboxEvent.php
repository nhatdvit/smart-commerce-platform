<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Transactional outbox.
 *
 * A row is written in the SAME database transaction as the business change, so
 * a committed order can never be missing its event and an event can never
 * describe an order that rolled back. A worker then publishes pending rows.
 *
 * Laravel dispatchAfterCommit is not a substitute: it fires after commit but
 * keeps nothing durable, so a crash between commit and dispatch loses the event
 * silently (CLAUDE.md section 16).
 *
 * @property string $id
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 */
final class OutboxEvent extends Model
{
    use HasUuids;

    public const string STATUS_PENDING = 'PENDING';

    public const string STATUS_PUBLISHED = 'PUBLISHED';

    public const string STATUS_FAILED = 'FAILED';

    public const UPDATED_AT = null;

    protected $table = 'outbox_events';

    protected $fillable = [
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'payload',
        'status',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'created_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }
}
