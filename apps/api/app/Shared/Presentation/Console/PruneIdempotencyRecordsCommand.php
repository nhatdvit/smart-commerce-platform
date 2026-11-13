<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Console;

use App\Shared\Infrastructure\Persistence\Model\IdempotencyRecord;
use Illuminate\Console\Command;

/**
 * Removes idempotency records past their retention window.
 *
 * Without this the table grows for the life of the system. The window is long
 * enough to cover client retries and support investigation, short enough to
 * keep the unique index small (COMMERCE_IDEMPOTENCY_RETENTION_HOURS).
 *
 * Deleting an expired record frees its key for reuse, which is correct: by
 * then the original response is no longer something a client could act on.
 */
final class PruneIdempotencyRecordsCommand extends Command
{
    protected $signature = 'idempotency:prune {--chunk=1000 : Rows to delete per statement}';

    protected $description = 'Delete idempotency records whose retention window has passed';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $deleted = 0;

        // Chunked so a large backlog does not hold one long transaction and
        // block writers on the same index.
        do {
            $removed = IdempotencyRecord::query()
                ->where('expires_at', '<', now())
                ->limit($chunk)
                ->delete();

            $deleted += $removed;
        } while ($removed > 0);

        if ($deleted > 0) {
            $this->info(sprintf('Pruned %d expired idempotency records.', $deleted));
        }

        return self::SUCCESS;
    }
}
