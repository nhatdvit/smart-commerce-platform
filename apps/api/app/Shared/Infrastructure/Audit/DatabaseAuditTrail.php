<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit;

use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;
use App\Shared\Infrastructure\Persistence\Model\AuditLog;
use App\Shared\Presentation\Http\Middleware\AssignRequestId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Persists the audit trail to `audit_logs`.
 *
 * Writes through Eloquent with no transaction of its own, which is deliberate:
 * it joins whatever transaction the caller opened, so the record and the action
 * commit together (§6). Opening its own transaction would break that and leave
 * audit rows for work that rolled back.
 *
 * The correlation id is attached automatically, so a row here can be lined up
 * with the request log and the outbox event from the same operation.
 */
final class DatabaseAuditTrail implements RecordsAuditTrail
{
    public function __construct(
        private readonly SensitiveDataRedactor $redactor,
        private readonly Request $request,
    ) {}

    public function record(AuditEntry $entry): void
    {
        AuditLog::query()->create([
            'store_id' => $entry->storeId,
            'actor_id' => $entry->actorId ?? $this->currentActorId(),
            'action' => $entry->action,
            'entity_type' => $entry->entityType,
            'entity_id' => $entry->entityId,
            // Redacted here rather than at the call site: one place to get
            // right, and no caller can forget.
            'changes' => $entry->changes === [] ? null : $this->redactor->redact($entry->changes),
            'request_id' => $this->currentRequestId(),
        ]);
    }

    /**
     * Null for actions with no signed-in actor: a scheduled job expiring an
     * order, or a guest operation. That is a real distinction worth keeping,
     * not a gap to fill with a placeholder.
     */
    private function currentActorId(): ?string
    {
        $id = Auth::id();

        return is_string($id) ? $id : null;
    }

    private function currentRequestId(): ?string
    {
        $id = $this->request->attributes->get('request_id')
            ?? $this->request->header(AssignRequestId::HEADER);

        return is_string($id) && $id !== '' ? $id : null;
    }
}
