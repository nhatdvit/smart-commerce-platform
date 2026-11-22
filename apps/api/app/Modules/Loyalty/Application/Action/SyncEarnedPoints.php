<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Action;

use App\Modules\Loyalty\Domain\Contract\LoyaltyLedger;
use App\Modules\Loyalty\Domain\Contract\LoyaltySettingsRepository;

/**
 * Awards points for every PAID payment this store has not yet been credited
 * for.
 *
 * The one place earning actually happens, and deliberately not reachable from
 * any HTTP route - nothing a customer or an operator does should be able to
 * invent points, the same reasoning
 * {@see \App\Modules\Payment\Application\Action\ConfirmPayment} keeps
 * settlement out of reach of anything but a verified transfer. Run instead
 * from the scheduler (`loyalty:sync-earned-points`), the same cadence
 * `outbox:dispatch` already uses.
 *
 * Idempotent by construction: {@see LoyaltyLedger::recordEarn()} is a no-op
 * for a payment already credited, so running this twice, or running it
 * against a payment this call already processed moments ago, awards nothing
 * twice.
 */
final readonly class SyncEarnedPoints
{
    public function __construct(
        private LoyaltyLedger $ledger,
        private LoyaltySettingsRepository $settings,
    ) {}

    /** @return int how many payments were newly credited */
    public function handle(string $storeId): int
    {
        $pending = $this->ledger->unsyncedPaidPayments($storeId);

        if ($pending === []) {
            return 0;
        }

        $settings = $this->settings->forStore($storeId);
        $credited = 0;

        foreach ($pending as $payment) {
            $points = $settings->pointsFor($payment['amount']);

            // A payment too small to earn a whole point has nothing to award,
            // and the ledger's own CHECK constraint refuses a zero-point EARN
            // row on principle (a balance is a plain SUM, and a row that adds
            // nothing is not a fact worth recording). It is simply skipped,
            // which means the next run re-checks it too - a real cost only at
            // a scale no single store's reconciliation queue approaches, and
            // far simpler than a second "already considered" marker.
            if ($points <= 0) {
                continue;
            }

            $this->ledger->recordEarn($storeId, $payment['phone'], $payment['paymentId'], $points);
            $credited++;
        }

        return $credited;
    }
}
