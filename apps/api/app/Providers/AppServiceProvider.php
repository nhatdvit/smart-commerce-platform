<?php

declare(strict_types=1);

namespace App\Providers;

use App\Shared\Application\Contract\RecordsAuditTrail;
use App\Shared\Application\Contract\RecordsDomainEvents;
use App\Shared\Infrastructure\Audit\DatabaseAuditTrail;
use App\Shared\Infrastructure\Config\EnvironmentValidator;
use App\Shared\Infrastructure\Idempotency\IdempotencyGuard;
use App\Shared\Infrastructure\Outbox\DatabaseOutbox;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Sleep;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EnvironmentValidator::class);

        // Audit: scoped, not singleton. It reads the current request for the
        // correlation id, and a queue worker handles many requests worth of
        // jobs in one process.
        $this->app->scoped(RecordsAuditTrail::class, DatabaseAuditTrail::class);

        // Outbox: stateless, so one instance is fine. The connection is
        // handed in rather than reached for, because DatabaseOutbox reads the
        // transaction level off it and that rule has to be testable.
        $this->app->singleton(
            RecordsDomainEvents::class,
            fn (): DatabaseOutbox => new DatabaseOutbox(
                $this->app->make(DatabaseManager::class)->connection(),
            ),
        );

        $this->app->singleton(
            IdempotencyGuard::class,
            static fn (): IdempotencyGuard => new IdempotencyGuard(
                (int) config('commerce.idempotency.retention_hours', 24),
            ),
        );
    }

    public function boot(): void
    {
        // A misconfigured deployment must refuse to serve rather than run on
        // silent defaults (CLAUDE.md §14).
        //
        // Only for requests and workers, not for arbitrary console commands:
        // build-time artisan calls (package:discover, config:cache) boot the
        // application before any environment exists, and blocking those would
        // make the image unbuildable. The container entrypoint and CI run
        // "php artisan app:validate-config" explicitly instead, so a bad
        // deployment still fails before it serves anything.
        if (! $this->app->runningInConsole()) {
            $this->app->make(EnvironmentValidator::class)->validate();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Shared\Presentation\Console\ValidateConfigurationCommand::class,
                \App\Shared\Presentation\Console\DispatchOutboxCommand::class,
                \App\Shared\Presentation\Console\PruneIdempotencyRecordsCommand::class,
            ]);
        }

        $this->configureAuthentication();
        $this->configureModels();
        $this->configureFactories();
        $this->configureRateLimiting();
        $this->configureDatabaseGuards();
    }

    private function configureAuthentication(): void
    {
        // The Authenticate middleware builds a guest redirect target before
        // throwing, and its default calls route('login') for any request that
        // does not send Accept: application/json. An API-only application
        // defines no such route, so that call raises RouteNotFoundException
        // and a clean 401 becomes a 500.
        //
        // Returning null means "no redirect": the exception handler then
        // produces the documented 401 envelope for every client, whatever
        // headers it sent.
        Authenticate::redirectUsing(static fn (): ?string => null);
    }

    private function configureFactories(): void
    {
        // Laravel guesses Database\Factories\<path-after-App\Models>Factory,
        // which does not exist in a modular layout: models live at
        // App\Modules\<Module>\Infrastructure\Persistence\Model\<Name>.
        // One flat rule keeps every module working without each model having
        // to override newFactory().
        Factory::guessFactoryNamesUsing(
            static function (string $modelName): string {
                /** @var class-string<Factory<Model>> $factory */
                $factory = 'Database\\Factories\\'.class_basename($modelName).'Factory';

                return $factory;
            },
        );
    }

    private function configureModels(): void
    {
        // Turn the classic Eloquent footguns into errors:
        //  - lazy loading hides N+1 queries until production traffic finds them
        //  - silently discarding a fillable mismatch loses writes
        //  - accessing a column that was not selected returns null, not an error
        Model::shouldBeStrict(! $this->app->isProduction());

        // Even in production, a missing attribute must never read as null: that
        // turns a typo into wrong money or a wrong status.
        Model::preventAccessingMissingAttributes();

        // Writes are explicit everywhere; mass assignment of unlisted columns
        // is a bug, not a convenience.
        Model::preventSilentlyDiscardingAttributes();
    }

    private function configureRateLimiting(): void
    {
        // Reads: generous, per authenticated user when known, per IP otherwise.
        RateLimiter::for('api', static fn (Request $request): Limit => Limit::perMinute(
            (int) config('commerce.rate_limits.read_per_minute', 120),
        )->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // Writes that create or settle money are limited far harder: checkout
        // and payment confirmation are the expensive, stateful operations.
        RateLimiter::for('commerce-write', static fn (Request $request): Limit => Limit::perMinute(
            (int) config('commerce.rate_limits.write_per_minute', 20),
        )->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // Authentication is limited per credential AND per IP, so neither a
        // single account nor a single host can be brute-forced cheaply.
        RateLimiter::for('login', static fn (Request $request): array => [
            Limit::perMinute(5)->by('login:'.(string) $request->input('email')),
            Limit::perMinute(20)->by('login-ip:'.$request->ip()),
        ]);
    }

    private function configureDatabaseGuards(): void
    {
        if ($this->app->isProduction()) {
            return;
        }

        // Surface slow and runaway queries during development, where there is
        // still time to fix the query plan.
        DB::whenQueryingForLongerThan(500, static function (): void {
            Log::warning('Database query budget exceeded for this request');
        });

        // Fake sleeps in tests so a retry/backoff test does not actually wait.
        if ($this->app->runningUnitTests()) {
            Sleep::fake();
        }
    }
}
