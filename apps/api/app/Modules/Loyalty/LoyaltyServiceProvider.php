<?php

declare(strict_types=1);

namespace App\Modules\Loyalty;

use App\Modules\Loyalty\Domain\Contract\LoyaltyLedger;
use App\Modules\Loyalty\Domain\Contract\LoyaltySettingsRepository;
use App\Modules\Loyalty\Domain\Contract\RewardRepository;
use App\Modules\Loyalty\Domain\Entity\Reward;
use App\Modules\Loyalty\Infrastructure\Persistence\EloquentLoyaltyLedger;
use App\Modules\Loyalty\Infrastructure\Persistence\EloquentLoyaltySettingsRepository;
use App\Modules\Loyalty\Infrastructure\Persistence\EloquentRewardRepository;
use App\Modules\Loyalty\Presentation\Console\SyncLoyaltyPointsCommand;
use App\Modules\Loyalty\Presentation\Policy\LoyaltyPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Loyalty module wiring.
 *
 * Points earned on paid orders, spent on rewards (CLAUDE.md section 5). The
 * earning side has no HTTP route - only the scheduler reaches
 * `SyncEarnedPoints`, registered in routes/console.php - so there is nothing
 * to bind for it beyond the repositories every use case already asks for by
 * interface.
 *
 * The policy is registered against the `Reward` entity, the one domain object
 * this module has (section 16); the dashboard, member and settings abilities
 * live on the same policy class and are checked against the same class-string,
 * never an instance.
 */
final class LoyaltyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoyaltyLedger::class, EloquentLoyaltyLedger::class);
        $this->app->singleton(RewardRepository::class, EloquentRewardRepository::class);
        $this->app->singleton(LoyaltySettingsRepository::class, EloquentLoyaltySettingsRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(Reward::class, LoyaltyPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SyncLoyaltyPointsCommand::class]);
        }
    }
}
