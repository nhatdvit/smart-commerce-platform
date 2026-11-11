<?php

declare(strict_types=1);

namespace App\Shared\Application\Contract;

use App\Shared\Application\Audit\AuditEntry;

/**
 * Writes the audit trail.
 *
 * Every module depends on this interface rather than on the audit_logs table,
 * so the storage can change without touching business code, and a test can
 * assert what would be recorded without a database.
 *
 * Implementations must write inside the caller transaction when there is one:
 * §6 requires the record of an action and the action itself to commit together
 * or not at all. An audit row for a payment confirmation that rolled back is
 * worse than no row, because it claims something happened that did not.
 */
interface RecordsAuditTrail
{
    public function record(AuditEntry $entry): void;
}
