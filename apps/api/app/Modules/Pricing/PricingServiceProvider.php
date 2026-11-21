<?php

declare(strict_types=1);

namespace App\Modules\Pricing;

use App\Modules\Pricing\Domain\Contract\CouponRepository;
use App\Modules\Pricing\Domain\Entity\Coupon;
use App\Modules\Pricing\Infrastructure\Persistence\EloquentCouponRepository;
use App\Modules\Pricing\Presentation\Policy\CouponPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Pricing module wiring.
 *
 * Pricing owns promotions, fees and server-authoritative totals (CLAUDE.md
 * section 5). What exists today is the first of those, and only its
 * definition half: a store can describe a discount code, list its codes and
 * retire them. Applying a code to a basket belongs to checkout, which is not
 * built - so nothing here computes a discount yet, and no other module
 * depends on this one.
 *
 * The policy is registered against the DOMAIN entity, so authorization is
 * expressed over the business object the use cases work with (section 16).
 */
final class PricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CouponRepository::class, EloquentCouponRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(Coupon::class, CouponPolicy::class);
    }
}
