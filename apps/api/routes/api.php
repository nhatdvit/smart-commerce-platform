<?php

declare(strict_types=1);

use App\Modules\Catalog\Presentation\Http\Controller\AdminProductController;
use App\Modules\Catalog\Presentation\Http\Controller\ProductImageController;
use App\Modules\Identity\Presentation\Http\Controller\SessionController;
use App\Modules\Menu\Presentation\Http\Controller\AdminMenuController;
use App\Modules\Menu\Presentation\Http\Controller\AdminMenuItemController;
use App\Modules\Order\Presentation\Http\Controller\AdminCustomerController;
use App\Modules\Order\Presentation\Http\Controller\AdminOrderController;
use App\Modules\Pricing\Presentation\Http\Controller\AdminCouponController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Versioned business API. The api/v1 prefix is applied in bootstrap/app.php.
 *
 * Built so far: the metadata endpoints, the session endpoints, and the admin
 * catalog, menu and order endpoints that drive the operations dashboard's menu
 * and order screens. The rest of CLAUDE.md section 8 arrives with its module:
 *
 *   GET    /stores/{id}                        Store
 *   GET    /menus/today                        Menu
 *   GET    /products, /products/{id}           Catalog (public)
 *   POST   /carts, /carts/{id}/items           Cart
 *   POST   /orders                             Order   (Idempotency-Key required)
 *   GET    /orders/{id}, /orders/{id}/payment  Order, Payment  (customer-facing)
 *   GET    /payment-methods                    Payment
 *   GET    /admin/payments                     Payment
 *   POST   /admin/payments/{id}/confirm        Payment
 *   PUT    /admin/stores/{id}/bank-account     Payment
 *
 * Admin routes are store-scoped. The convention, enforced by middleware, is:
 *
 *   Route::put('/admin/stores/{store}/bank-account', ...)
 *       ->middleware(['auth:sanctum', 'store.resolve', 'store.can:payment.bank-account.manage']);
 *
 * store.resolve decides WHICH store from trusted sources only, and store.can
 * checks that this user holds the permission IN that store. Both, every time:
 * dropping either one is a privilege-escalation bug.
 */

Route::get('/', static fn (Request $request): array => [
    'name' => config('app.name'),
    'api_version' => 'v1',
    'request_id' => $request->attributes->get('request_id'),
])->name('api.index');

Route::get('/me', static fn (Request $request): array => [
    'id' => $request->user()?->getAuthIdentifier(),
])->middleware('auth:sanctum')->name('api.me');

/*
 * Session lifecycle for the first-party web apps (cookie authentication).
 * Rate limited per credential and per IP by the `login` limiter.
 */
Route::post('/auth/login', [SessionController::class, 'login'])
    ->middleware('throttle:login')
    ->name('api.auth.login');

Route::post('/auth/logout', [SessionController::class, 'logout'])
    ->middleware('auth:sanctum')
    ->name('api.auth.logout');

/*
 * Operations dashboard: catalog master data and menus.
 *
 * Every route carries all three guards. store.resolve takes the store from the
 * host or the route - never from a query parameter - and store.can checks the
 * permission IN that store. The controllers then authorize the OBJECT through a
 * policy, which is the check that catches an id from another store arriving on
 * a route the caller may otherwise use.
 */
/*
 * Uploaded product photography, served to anyone: it is what a storefront
 * shows. The file name is a random UUID, and both parameters are constrained
 * below, so nothing here can be enumerated or walked.
 */
Route::get('/media/products/{store}/{file}', [ProductImageController::class, 'show'])
    ->whereUuid('store')
    ->where('file', '[0-9a-f-]{36}\.(jpg|png|webp)')
    ->name('api.media.product-image');

Route::middleware(['auth:sanctum', 'store.resolve'])
    ->prefix('admin')
    ->name('api.admin.')
    ->group(static function (): void {
        // Before /products/{product}, or the literal path would be read as a
        // product id - and refused, since that id is not a UUID.
        Route::post('/products/images', [ProductImageController::class, 'store'])
            ->middleware('store.can:catalog.manage')
            ->name('products.images.store');

        Route::get('/products', [AdminProductController::class, 'index'])
            ->middleware('store.can:catalog.read')
            ->name('products.index');

        Route::post('/products', [AdminProductController::class, 'store'])
            ->middleware('store.can:catalog.manage')
            ->name('products.store');

        Route::put('/products/{product}', [AdminProductController::class, 'update'])
            ->middleware('store.can:catalog.manage')
            ->whereUuid('product')
            ->name('products.update');

        Route::get('/menus', [AdminMenuController::class, 'index'])
            ->middleware('store.can:menu.read')
            ->name('menus.index');

        Route::get('/menus/{menu}', [AdminMenuController::class, 'show'])
            ->middleware('store.can:menu.read')
            ->whereUuid('menu')
            ->name('menus.show');

        Route::put('/menus/{menu}', [AdminMenuController::class, 'update'])
            ->middleware('store.can:menu.manage')
            ->whereUuid('menu')
            ->name('menus.update');

        /*
         * The morning prep sheet, for the whole menu at once.
         *
         * Declared before the {menuItem} routes so the literal path is not
         * read as an id - it would be refused anyway, since that id must be a
         * UUID, but the order is what makes the intent obvious.
         */
        Route::put('/menu-items/daily-portions', [AdminMenuItemController::class, 'dailyPortions'])
            ->middleware('store.can:menu.manage|kitchen.operate')
            ->name('menu-items.daily-portions');

        // Either grant is enough here: marking an item sold out is the
        // most-used action in a working kitchen and must not need a manager.
        Route::put('/menu-items/{menuItem}/availability', [AdminMenuItemController::class, 'update'])
            ->middleware('store.can:menu.manage|kitchen.operate')
            ->whereUuid('menuItem')
            ->name('menu-items.availability');

        /*
         * The operations queue.
         *
         * The transition route admits either grant, because a cook has to be
         * able to move an order to PREPARING and READY without a manager. Which
         * moves a cook may actually make is decided by the policy, not by the
         * route: `kitchen.operate` alone cannot complete or cancel an order.
         */
        Route::get('/orders', [AdminOrderController::class, 'index'])
            ->middleware('store.can:order.read')
            ->name('orders.index');

        Route::get('/orders/{order}', [AdminOrderController::class, 'show'])
            ->middleware('store.can:order.read')
            ->whereUuid('order')
            ->name('orders.show');

        Route::post('/orders/{order}/status', [AdminOrderController::class, 'transition'])
            ->middleware(['store.can:order.transition|kitchen.operate', 'throttle:commerce-write'])
            ->whereUuid('order')
            ->name('orders.status');

        /*
         * The guest-customer directory: orders grouped by contact phone.
         * Same `order.read` grant as the queue above - there is no dedicated
         * Customer module yet to give this a permission of its own.
         */
        Route::get('/customers', [AdminCustomerController::class, 'index'])
            ->middleware('store.can:order.read')
            ->name('customers.index');

        /*
         * Discount codes.
         *
         * A coupon is money the shop gives away, so reading and writing are
         * separate grants and neither is a cashier's: promotion.manage sits
         * with the owner and the manager (see RolePermissionSeeder).
         */
        Route::get('/coupons', [AdminCouponController::class, 'index'])
            ->middleware('store.can:promotion.read')
            ->name('coupons.index');

        Route::post('/coupons', [AdminCouponController::class, 'store'])
            ->middleware('store.can:promotion.manage')
            ->name('coupons.store');

        Route::put('/coupons/{coupon}', [AdminCouponController::class, 'update'])
            ->middleware('store.can:promotion.manage')
            ->whereUuid('coupon')
            ->name('coupons.update');
    });
