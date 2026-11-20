<?php

declare(strict_types=1);

namespace App\Modules\Payment;

use App\Modules\Payment\Application\Contract\ProvidesPaymentSummaries;
use App\Modules\Payment\Infrastructure\Persistence\EloquentPaymentSummaryProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Payment module wiring.
 *
 * This milestone registers one binding: the exported read model the operations
 * queue uses to show how an order is being paid. There is no endpoint, no
 * policy and no write path yet, because settlement is the payments screen's
 * slice - bank account configuration, dynamic VietQR and manual staff
 * confirmation arrive together, since none of the three means anything alone
 * (CLAUDE.md section 6).
 *
 * The module owns the `payments` table and nothing else reads it.
 */
final class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProvidesPaymentSummaries::class, EloquentPaymentSummaryProvider::class);
    }
}
