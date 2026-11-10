<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence\Model;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Append-only record of security- and business-sensitive actions.
 *
 * Rows are never updated or deleted. The changes payload must be redacted
 * before it gets here: no passwords, tokens, secrets or unnecessary personal
 * data (CLAUDE.md section 10).
 *
 * @property string $id
 * @property string|null $store_id
 * @property string|null $actor_id
 * @property string $action
 * @property string $entity_type
 * @property string $entity_id
 * @property array<string, mixed>|null $changes
 * @property string|null $request_id
 */
final class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'audit_logs';

    protected $fillable = [
        'store_id',
        'actor_id',
        'action',
        'entity_type',
        'entity_id',
        'changes',
        'request_id',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
