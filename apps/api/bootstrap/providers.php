<?php

declare(strict_types=1);

use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Store\StoreServiceProvider;
use App\Providers\AppServiceProvider;

/**
 * Module providers are listed explicitly rather than auto-discovered, so the
 * set of active modules is one readable list and load order is deterministic
 * (CLAUDE.md section 4).
 *
 * Remaining modules from CLAUDE.md section 5 - Catalog, Menu, Inventory, Cart,
 * Pricing, Order, Payment, Fulfillment, Kitchen, Customer, Notification,
 * Reporting, Audit - are added here as each milestone implements them. They are
 * deliberately not pre-registered as empty scaffolds.
 */
return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    StoreServiceProvider::class,
];
