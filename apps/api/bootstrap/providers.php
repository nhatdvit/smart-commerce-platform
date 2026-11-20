<?php

declare(strict_types=1);

use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Menu\MenuServiceProvider;
use App\Modules\Order\OrderServiceProvider;
use App\Modules\Payment\PaymentServiceProvider;
use App\Modules\Store\StoreServiceProvider;
use App\Providers\AppServiceProvider;

/**
 * Module providers are listed explicitly rather than auto-discovered, so the
 * set of active modules is one readable list and load order is deterministic
 * (CLAUDE.md section 4).
 *
 * Remaining modules from CLAUDE.md section 5 - Inventory, Cart, Pricing,
 * Fulfillment, Kitchen, Customer, Notification, Reporting, Audit - are added
 * here as each milestone implements them. They are deliberately not
 * pre-registered as empty scaffolds.
 *
 * Order matters: Menu binds nothing of Catalog's and Order binds nothing of
 * Payment's, but each resolves the other's exported read model at runtime, so
 * the module being read from is registered first.
 */
return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    StoreServiceProvider::class,
    CatalogServiceProvider::class,
    MenuServiceProvider::class,
    PaymentServiceProvider::class,
    OrderServiceProvider::class,
];
