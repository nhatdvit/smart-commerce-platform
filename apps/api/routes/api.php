<?php

declare(strict_types=1);

use App\Modules\Catalog\Presentation\Http\Controller\AdminProductController;
use App\Modules\Catalog\Presentation\Http\Controller\ProductImageController;
use App\Modules\Identity\Presentation\Http\Controller\SessionController;
use App\Modules\Menu\Presentation\Http\Controller\AdminMenuController;
use App\Modules\Menu\Presentation\Http\Controller\AdminMenuItemController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Versioned business API. The api/v1 prefix is applied in bootstrap/app.php.
 *
 * Built so far: the metadata endpoints, the session endpoints and the admin
 * catalog and menu endpoints the operations dashboard's menu screen drives.
 * The rest of CLAUDE.md section 8 arrives with its module:
 *
 *   GET    /stores/{id}                        Store
 *   GET    /menus/today                        Menu
 *   GET    /products, /products/{id}           Catalog (public)
 *   POST   /carts, /carts/{id}/items           Cart
 *   POST   /orders                             Order   (Idempotency-Key required)
 *   GET    /orders/{id}, /orders/{id}/payment  Order, Payment
 *   GET    /payment-methods                    Payment
 *   GET    /admin/orders                       Order
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

        // Either grant is enough here: marking an item sold out is the
        // most-used action in a working kitchen and must not need a manager.
        Route::put('/menu-items/{menuItem}/availability', [AdminMenuItemController::class, 'update'])
            ->middleware('store.can:menu.manage|kitchen.operate')
            ->whereUuid('menuItem')
            ->name('menu-items.availability');
    });
