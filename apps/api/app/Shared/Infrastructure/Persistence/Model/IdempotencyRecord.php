<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Stored outcome of an idempotent request.
 *
 * Order creation and payment confirmation must be safe to retry: a client that
 * times out and retries has to get the first result back, not a second order.
 * The unique index on (scope, key) is what makes the retry collide instead of
 * duplicating work.
 *
 * A key reused with a different request_hash is rejected, because that is a
 * client bug and replaying the old response would be wrong.
 *
 * @property string $id
 * @property string $scope
 * @property string $key
 * @property string $request_hash
 * @property string $status
 * @property int|null $response_status
 * @property array<string, mixed>|null $response_body
 */
final class IdempotencyRecord extends Model
{
    use HasUuids;

    public const string STATUS_IN_PROGRESS = 'IN_PROGRESS';

    public const string STATUS_COMPLETED = 'COMPLETED';

    public const string STATUS_FAILED = 'FAILED';

    protected $table = 'idempotency_records';

    protected $fillable = [
        'scope',
        'key',
        'request_hash',
        'status',
        'response_status',
        'response_body',
        'expires_at',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'response_status' => 'integer',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
