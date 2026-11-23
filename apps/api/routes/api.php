<?php

declare(strict_types=1);

use App\Modules\Catalog\Presentation\Http\Controller\AdminProductController;
use App\Modules\Catalog\Presentation\Http\Controller\ProductImageController;
use App\Modules\Identity\Presentation\Http\Controller\ProfileController;
use App\Modules\Identity\Presentation\Http\Controller\RegistrationController;
use App\Modules\Identity\Presentation\Http\Controller\SessionController;
use App\Modules\Loyalty\Presentation\Http\Controller\AdminLoyaltyController;
use App\Modules\Loyalty\Presentation\Http\Controller\AdminRewardController;
use App\Modules\Loyalty\Presentation\Http\Controller\CustomerLoyaltyController;
use App\Modules\Menu\Presentation\Http\Controller\AdminMenuController;
use App\Modules\Menu\Presentation\Http\Controller\AdminMenuItemController;
use App\Modules\Menu\Presentation\Http\Controller\PublicMenuController;
use App\Modules\Order\Presentation\Http\Controller\AdminCustomerController;
use App\Modules\Order\Presentation\Http\Controller\AdminOrderController;
use App\Modules\Order\Presentation\Http\Controller\CustomerOrderController;
use App\Modules\Payment\Presentation\Http\Controller\AdminPaymentController;
use App\Modules\Payment\Presentation\Http\Controller\AdminStorePaymentSettingsController;
use App\Modules\Payment\Presentation\Http\Controller\PublicPaymentMethodController;
use App\Modules\Pricing\Presentation\Http\Controller\AdminCouponController;
use App\Modules\Store\Presentation\Http\Controller\AdminStoreContextController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Versioned business API. The api/v1 prefix is applied in bootstrap/app.php.
 *
 * Built so far: the metadata endpoints, the session endpoints (including
 * customer self-registration by phone), a signed-in customer's own profile/
 * password/loyalty-standing endpoints, the public today's-menu endpoint that
 * drives the customer storefront's home screen, checkout (`POST /orders` and
 * the two reads after it - see CustomerOrderController), and the admin
 * catalog, menu, order, discount-code and payment endpoints that drive the
 * operations dashboard. The rest of CLAUDE.md section 8 arrives with its
 * module:
 *
 *   GET    /stores/{id}                        Store
 *   GET    /products, /products/{id}           Catalog (public)
 *   POST   /carts, /carts/{id}/items           Cart
 *
 * `POST /orders` does not reference a cart: no Cart module exists yet, so it
 * takes line items directly - see CreateOrder's own doc comment for why that
 * is the smallest complete slice rather than a cart-id placeholder.
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

Route::get('/me', [SessionController::class, 'me'])
    ->middleware('auth:sanctum')
    ->name('api.me');

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
 * Customer self-registration by phone number (CLAUDE.md section 5: staff
 * accounts are provisioned by an admin, never self-service). Signs the new
 * account straight in, so it is rate limited by its own `register` limiter
 * rather than `login`'s.
 */
Route::post('/auth/register', [RegistrationController::class, 'register'])
    ->middleware('throttle:register')
    ->name('api.auth.register');

/*
 * A signed-in customer's own profile, password and loyalty standing. Scoped
 * to the caller by the session alone - never by an id in the URL or payload.
 */
Route::get('/customer/profile', [ProfileController::class, 'show'])
    ->middleware('auth:sanctum')
    ->name('api.customer.profile.show');

Route::put('/customer/profile', [ProfileController::class, 'update'])
    ->middleware('auth:sanctum')
    ->name('api.customer.profile.update');

Route::put('/customer/password', [ProfileController::class, 'changePassword'])
    ->middleware('auth:sanctum')
    ->name('api.customer.password.update');

Route::get('/customer/loyalty', [CustomerLoyaltyController::class, 'show'])
    ->middleware(['auth:sanctum', 'store.resolve'])
    ->name('api.customer.loyalty.show');

/*
 * A signed-in customer's own order history. auth:sanctum here, unlike the
 * /orders routes below: a guest has no durable identity to list by, only
 * the one-time token for the single order they just placed.
 */
Route::get('/customer/orders', [CustomerOrderController::class, 'mine'])
    ->middleware(['auth:sanctum', 'store.resolve'])
    ->name('api.customer.orders.index');

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

/*
 * The customer storefront's home screen: today's menu, grouped by category.
 * Public on purpose - a menu is what a shop puts in its window - so this
 * carries `store.resolve` alone, never `auth:sanctum` or `store.can`.
 */
Route::get('/menus/today', [PublicMenuController::class, 'today'])
    ->middleware('store.resolve')
    ->name('api.menus.today');

/*
 * This store's accepted payment methods - public, so the checkout screen can
 * show (and explain) its choices before anyone signs in.
 */
Route::get('/payment-methods', [PublicPaymentMethodController::class, 'index'])
    ->middleware('store.resolve')
    ->name('api.payment-methods.index');

/*
 * Checkout, and the two reads a customer needs afterwards. Reachable by a
 * guest or a signed-in customer alike - never auth:sanctum - see
 * CustomerOrderController's own doc comment for how each is authorized.
 * Idempotency-Key is required on creation (CLAUDE.md section 6): a retried
 * submit must never place the same order twice.
 */
Route::post('/orders', [CustomerOrderController::class, 'store'])
    ->middleware(['store.resolve', 'idempotent:order.create', 'throttle:commerce-write'])
    ->name('api.orders.create');

Route::get('/orders/{order}', [CustomerOrderController::class, 'show'])
    ->whereUuid('order')
    ->middleware('store.resolve')
    ->name('api.orders.show');

Route::get('/orders/{order}/payment', [CustomerOrderController::class, 'payment'])
    ->whereUuid('order')
    ->middleware('store.resolve')
    ->name('api.orders.payment');

/*
 * Self-service cancel, allowed only before PREPARING (CancelOrder's own doc
 * comment). No idempotency key: a retried cancel on an already-cancelled
 * order simply answers the same 409 a staff transition does.
 */
Route::post('/orders/{order}/cancel', [CustomerOrderController::class, 'cancel'])
    ->whereUuid('order')
    ->middleware(['store.resolve', 'throttle:commerce-write'])
    ->name('api.orders.cancel');

Route::middleware(['auth:sanctum', 'store.resolve'])
    ->prefix('admin')
    ->name('api.admin.')
    ->group(static function (): void {
        // Which store this session is scoped to - no permission beyond
        // authentication, since every screen already discloses it just by
        // what it shows. Lets the dashboard build the two payment-settings
        // URLs below without an operator ever typing a store id.
        Route::get('/store', [AdminStoreContextController::class, 'show'])
            ->name('store.show');

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

        /*
         * The reconciliation queue: manual verification of cash and bank
         * transfers, exactly as CLAUDE.md section 6 fixes the MVP settlement
         * flow. Confirming is the only way a payment becomes PAID, so it is
         * both idempotent (a retried request replays its first outcome, never
         * a second confirmation) and rate-limited the same as every other
         * write that settles money.
         */
        Route::get('/payments', [AdminPaymentController::class, 'index'])
            ->middleware('store.can:payment.read')
            ->name('payments.index');

        Route::get('/payments/{payment}', [AdminPaymentController::class, 'show'])
            ->middleware('store.can:payment.read')
            ->whereUuid('payment')
            ->name('payments.show');

        Route::post('/payments/{payment}/confirm', [AdminPaymentController::class, 'confirm'])
            ->middleware(['store.can:payment.confirm', 'idempotent:payment.confirm', 'throttle:commerce-write'])
            ->whereUuid('payment')
            ->name('payments.confirm');

        Route::post('/payments/{payment}/flag', [AdminPaymentController::class, 'flag'])
            ->middleware(['store.can:payment.confirm', 'throttle:commerce-write'])
            ->whereUuid('payment')
            ->name('payments.flag');

        Route::post('/payments/{payment}/regenerate-qr', [AdminPaymentController::class, 'regenerateQr'])
            ->middleware(['store.can:payment.confirm', 'throttle:commerce-write'])
            ->whereUuid('payment')
            ->name('payments.regenerate-qr');

        // Static reference data for the bank-account form's picker - not
        // store-scoped data itself, gated the same as that form because it
        // exists only to serve it.
        Route::get('/known-banks', [AdminStorePaymentSettingsController::class, 'showKnownBanks'])
            ->middleware('store.can:payment.bank-account.manage')
            ->name('known-banks.show');

        /*
         * Store-level payment configuration. `{store}` is an explicit route
         * parameter here (every other admin route above resolves its store
         * from the host or the single-store default) because these settings
         * are the one place CLAUDE.md's own example names a store id in the
         * path - see the file header note.
         */
        Route::get('/stores/{store}/bank-account', [AdminStorePaymentSettingsController::class, 'showBankAccount'])
            ->middleware('store.can:payment.bank-account.manage')
            ->name('stores.bank-account.show');

        Route::put('/stores/{store}/bank-account', [AdminStorePaymentSettingsController::class, 'updateBankAccount'])
            ->middleware('store.can:payment.bank-account.manage')
            ->name('stores.bank-account.update');

        Route::get('/stores/{store}/payment-methods', [AdminStorePaymentSettingsController::class, 'showPaymentMethods'])
            ->middleware('store.can:store.update')
            ->name('stores.payment-methods.show');

        Route::put('/stores/{store}/payment-methods', [AdminStorePaymentSettingsController::class, 'updatePaymentMethods'])
            ->middleware('store.can:store.update')
            ->name('stores.payment-methods.update');

        /*
         * Loyalty: points earned on paid orders, spent on rewards.
         *
         * `loyalty.read` is everyday visibility, `loyalty.redeem` is spending
         * points at the counter and `loyalty.manage` is deciding the program
         * itself - the same three-way split promotion.* draws for discount
         * codes (see RolePermissionSeeder). A member is identified by phone,
         * not a route id, since there is no registered-account module yet
         * (CLAUDE.md section 5).
         */
        Route::get('/loyalty/summary', [AdminLoyaltyController::class, 'summary'])
            ->middleware('store.can:loyalty.read')
            ->name('loyalty.summary');

        Route::get('/loyalty/members', [AdminLoyaltyController::class, 'members'])
            ->middleware('store.can:loyalty.read')
            ->name('loyalty.members.index');

        Route::get('/loyalty/members/{phone}', [AdminLoyaltyController::class, 'show'])
            ->middleware('store.can:loyalty.read')
            ->where('phone', '[0-9+]{6,20}')
            ->name('loyalty.members.show');

        Route::post('/loyalty/members/{phone}/redeem', [AdminLoyaltyController::class, 'redeem'])
            ->middleware(['store.can:loyalty.redeem', 'throttle:commerce-write'])
            ->where('phone', '[0-9+]{6,20}')
            ->name('loyalty.members.redeem');

        Route::get('/loyalty/rewards', [AdminRewardController::class, 'index'])
            ->middleware('store.can:loyalty.read')
            ->name('loyalty.rewards.index');

        Route::post('/loyalty/rewards', [AdminRewardController::class, 'store'])
            ->middleware('store.can:loyalty.manage')
            ->name('loyalty.rewards.store');

        Route::put('/loyalty/rewards/{reward}', [AdminRewardController::class, 'update'])
            ->middleware('store.can:loyalty.manage')
            ->whereUuid('reward')
            ->name('loyalty.rewards.update');

        Route::get('/loyalty/settings', [AdminLoyaltyController::class, 'settings'])
            ->middleware('store.can:loyalty.read')
            ->name('loyalty.settings.show');

        Route::put('/loyalty/settings', [AdminLoyaltyController::class, 'updateSettings'])
            ->middleware('store.can:loyalty.manage')
            ->name('loyalty.settings.update');
    });
