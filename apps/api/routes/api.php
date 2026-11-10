<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Versioned business API. The api/v1 prefix is applied in bootstrap/app.php.
 *
 * Phase 01 exposes only the metadata endpoint below. The customer and
 * operations endpoints from CLAUDE.md section 8 arrive with their modules:
 *
 *   GET    /stores/{id}                        Store
 *   GET    /menus/today                        Menu
 *   GET    /products, /products/{id}           Catalog
 *   POST   /carts, /carts/{id}/items           Cart
 *   POST   /orders                             Order   (Idempotency-Key required)
 *   GET    /orders/{id}, /orders/{id}/payment  Order, Payment
 *   GET    /payment-methods                    Payment
 *   GET    /admin/orders                       Order
 *   PUT    /admin/menus/{id}                   Menu
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
 * checks that this user holds the permission IN that store.
 */

Route::get('/', static fn (Request $request): array => [
    'name' => config('app.name'),
    'api_version' => 'v1',
    'request_id' => $request->attributes->get('request_id'),
])->name('api.index');

Route::get('/me', static fn (Request $request): array => [
    'id' => $request->user()?->getAuthIdentifier(),
])->middleware('auth:sanctum')->name('api.me');
