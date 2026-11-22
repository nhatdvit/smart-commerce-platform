<?php

declare(strict_types=1);

namespace App\Modules\Payment;

use App\Modules\Payment\Application\Action\GenerateBankTransferInstructions;
use App\Modules\Payment\Application\Contract\ProvidesPaymentSummaries;
use App\Modules\Payment\Domain\Contract\BankAccountRepository;
use App\Modules\Payment\Domain\Contract\PaymentMethodRepository;
use App\Modules\Payment\Domain\Contract\PaymentRepository;
use App\Modules\Payment\Domain\Entity\Payment;
use App\Modules\Payment\Infrastructure\Persistence\EloquentBankAccountRepository;
use App\Modules\Payment\Infrastructure\Persistence\EloquentPaymentMethodRepository;
use App\Modules\Payment\Infrastructure\Persistence\EloquentPaymentRepository;
use App\Modules\Payment\Infrastructure\Persistence\EloquentPaymentSummaryProvider;
use App\Modules\Payment\Presentation\Policy\PaymentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Payment module wiring.
 *
 * Now carries the whole MVP settlement slice: the reconciliation queue,
 * manual confirmation and flagging, the store's bank account and its payment
 * method switches, and VietQR generation. `ProvidesPaymentSummaries` remains
 * the thin read-only contract Order depends on; everything else here is
 * private to this module's own admin endpoints.
 *
 * The policy is registered against the DOMAIN entity, so authorization is
 * expressed over the business object the use cases work with (CLAUDE.md
 * section 16).
 */
final class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProvidesPaymentSummaries::class, EloquentPaymentSummaryProvider::class);
        $this->app->singleton(PaymentRepository::class, EloquentPaymentRepository::class);
        $this->app->singleton(BankAccountRepository::class, EloquentBankAccountRepository::class);
        $this->app->singleton(PaymentMethodRepository::class, EloquentPaymentMethodRepository::class);

        // The prefix is config because the reference format is, in part, an
        // operational choice (CLAUDE.md §16 config.php: "read through config(),
        // never env(), outside this file").
        $this->app->when(GenerateBankTransferInstructions::class)
            ->needs('$referencePrefix')
            ->give(static fn (): string => (string) config('commerce.bank_transfer.reference_prefix', 'SCP'));
    }

    public function boot(): void
    {
        Gate::policy(Payment::class, PaymentPolicy::class);
    }
}
