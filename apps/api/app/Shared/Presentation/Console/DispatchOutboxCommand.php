<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Console;

use App\Shared\Infrastructure\Outbox\DispatchPendingOutboxEvents;
use Illuminate\Console\Command;

/**
 * Scheduled entry point for the outbox dispatcher.
 *
 * Registered in routes/console.php to run every minute without overlapping.
 * Exactly one scheduler runs per deployment (CLAUDE.md section 16), and the
 * dispatcher locks rows anyway, so a stray second run is safe.
 */
final class DispatchOutboxCommand extends Command
{
    protected $signature = 'outbox:dispatch {--batch=100 : Maximum rows to publish in one run}';

    protected $description = 'Publish pending outbox events to the queue';

    public function handle(): int
    {
        $batch = (int) $this->option('batch');
        $dispatcher = new DispatchPendingOutboxEvents(max(1, $batch));

        $result = $dispatcher();

        if ($result['published'] > 0 || $result['failed'] > 0) {
            $this->info(sprintf(
                'Outbox: published %d, failed %d',
                $result['published'],
                $result['failed'],
            ));
        }

        // A failure here is visible in logs and metrics; the command itself
        // still succeeds so the scheduler does not treat a single bad event as
        // a reason to stop running.
        return self::SUCCESS;
    }
}
