<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Console;

use App\Modules\Loyalty\Application\Action\SyncEarnedPoints;
use App\Modules\Store\Application\StoreDirectory;
use Illuminate\Console\Command;
use Throwable;

/**
 * Scheduled entry point for earning points.
 *
 * Registered in routes/console.php to run every minute, the same cadence
 * `outbox:dispatch` uses - this module deliberately does not react to a
 * payment outbox event (see the migration note on why), so this sweep is how
 * a PAID payment ever turns into points at all. One store at a time, so one
 * store's failure never stops another's from being credited.
 */
final class SyncLoyaltyPointsCommand extends Command
{
    protected $signature = 'loyalty:sync-earned-points';

    protected $description = 'Award loyalty points for payments not yet credited, for every active store';

    public function __construct(
        private readonly SyncEarnedPoints $sync,
        private readonly StoreDirectory $stores,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $credited = 0;

        foreach ($this->stores->activeIds() as $storeId) {
            try {
                $credited += $this->sync->handle($storeId);
            } catch (Throwable $e) {
                $this->error(sprintf('Loyalty sync failed for store %s: %s', $storeId, $e->getMessage()));
                report($e);
            }
        }

        if ($credited > 0) {
            $this->info(sprintf('Loyalty: credited %d payment(s)', $credited));
        }

        return self::SUCCESS;
    }
}
