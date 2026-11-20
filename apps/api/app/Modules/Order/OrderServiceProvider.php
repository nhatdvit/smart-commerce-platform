<?php

declare(strict_types=1);

namespace App\Modules\Order;

use App\Modules\Order\Domain\Contract\OrderRepository;
use App\Modules\Order\Domain\Entity\Order;
use App\Modules\Order\Infrastructure\Persistence\EloquentOrderRepository;
use App\Modules\Order\Presentation\Policy\OrderPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Order module wiring.
 *
 * The module depends on two other modules, both only through their exported
 * application services: Payment's `ProvidesPaymentSummaries` to show how an
 * order is being paid, and Identity's `StaffDirectory` to name whoever moved
 * it. Nothing here touches another module's tables (CLAUDE.md section 3).
 *
 * The policy is registered against the DOMAIN entity, not an Eloquent model,
 * so authorization is expressed over the business object the use cases work
 * with (section 16).
 */
final class OrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OrderRepository::class, EloquentOrderRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(Order::class, OrderPolicy::class);
    }
}
