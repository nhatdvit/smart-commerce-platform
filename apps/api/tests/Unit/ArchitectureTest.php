<?php

declare(strict_types=1);

/*
 * The layering rules from CLAUDE.md §3 and §16, enforced as tests.
 *
 * These exist because architecture rules written only in a document decay. A
 * failure here is a design error: the fix is to move the dependency, not to
 * relax the test.
 */

arch('domain layers do not depend on the framework')
    ->expect('App\Modules')
    ->toOnlyBeUsedIn('App')
    ->ignoring('App\Modules');

arch('the domain layer knows nothing about Laravel, Eloquent or HTTP')
    ->expect('App\Modules\Identity\Domain')
    ->not->toUse([
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Http\Request',
        'Illuminate\Support\Facades',
    ]);

arch('the store domain layer is equally framework-free')
    ->expect('App\Modules\Store\Domain')
    ->not->toUse([
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Http\Request',
        'Illuminate\Support\Facades',
    ]);

arch('the catalog domain layer is framework-free')
    ->expect('App\Modules\Catalog\Domain')
    ->not->toUse([
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Http\Request',
        'Illuminate\Support\Facades',
    ]);

arch('the menu domain layer is framework-free')
    ->expect('App\Modules\Menu\Domain')
    ->not->toUse([
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Http\Request',
        'Illuminate\Support\Facades',
    ]);

arch('the order domain layer is framework-free')
    ->expect('App\Modules\Order\Domain')
    ->not->toUse([
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Http\Request',
        'Illuminate\Support\Facades',
    ]);

arch('the payment domain layer is framework-free')
    ->expect('App\Modules\Payment\Domain')
    ->not->toUse([
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Http\Request',
        'Illuminate\Support\Facades',
    ]);

/*
 * The one allowed direction between these two modules.
 *
 * Menu reads the catalog through Catalog's exported application contract and
 * its DTO, never through its tables or its Eloquent models. Catalog knows
 * nothing of Menu at all, which is what keeps a retail catalogue possible
 * without a menu (CLAUDE.md sections 3 and 16).
 */
arch('the menu module reaches the catalog only through its exported contract')
    ->expect('App\Modules\Menu')
    ->not->toUse([
        'App\Modules\Catalog\Infrastructure',
        'App\Modules\Catalog\Domain',
        'App\Modules\Catalog\Presentation',
    ]);

arch('the catalog module does not depend on the menu module')
    ->expect('App\Modules\Catalog')
    ->not->toUse('App\Modules\Menu');

/*
 * The same one allowed direction between Order and Payment.
 *
 * Order reads how an order is being paid through Payment's exported contract
 * and its DTO, never through the payments table. Payment knows nothing of the
 * Order module at all: its rows reference an order id, which is a schema fact,
 * not a code dependency - so settlement can grow its bank snapshot and
 * reconciliation columns without the Order module noticing (sections 3 and 6).
 */
arch('the order module reaches payments only through their exported contract')
    ->expect('App\Modules\Order')
    ->not->toUse([
        'App\Modules\Payment\Infrastructure',
        'App\Modules\Payment\Presentation',
    ]);

arch('the payment module does not depend on the order module')
    ->expect('App\Modules\Payment')
    ->not->toUse('App\Modules\Order');

/*
 * Order reads other modules only through their application services.
 *
 * Staff names come from Identity's directory and menu prices are already
 * snapshotted on the line, so the Order module has no reason to touch another
 * module's tables - and the test says so rather than trusting a convention.
 */
arch('the order module does not read other modules persistence')
    ->expect('App\Modules\Order')
    ->not->toUse([
        'App\Modules\Identity\Infrastructure',
        'App\Modules\Menu\Infrastructure',
        'App\Modules\Catalog\Infrastructure',
    ]);

arch('shared domain code is framework-free')
    ->expect('App\Shared\Domain')
    ->not->toUse([
        'Illuminate\Database\Eloquent\Model',
        'Illuminate\Http\Request',
    ]);

arch('presentation does not reach into persistence models directly')
    ->expect('App\Shared\Presentation')
    ->not->toUse([
        'App\Modules\Identity\Infrastructure\Persistence\Model',
        'App\Modules\Store\Infrastructure\Persistence\Model',
    ]);

arch('every class declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('nothing left behind from debugging')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('enums are final by nature and models are explicitly final')
    ->expect('App\Modules\Identity\Infrastructure\Persistence\Model')
    ->classes()
    ->toBeFinal();

arch('catalog, menu, order and payment models are final too')
    ->expect([
        'App\Modules\Catalog\Infrastructure\Persistence\Model',
        'App\Modules\Menu\Infrastructure\Persistence\Model',
        'App\Modules\Order\Infrastructure\Persistence\Model',
        'App\Modules\Payment\Infrastructure\Persistence\Model',
    ])
    ->classes()
    ->toBeFinal();

arch('contracts are interfaces')
    ->expect('App\Modules\Identity\Application\Contract')
    ->toBeInterfaces();

arch('every module contract is an interface')
    ->expect([
        'App\Modules\Catalog\Application\Contract',
        'App\Modules\Catalog\Domain\Contract',
        'App\Modules\Menu\Domain\Contract',
        'App\Modules\Order\Domain\Contract',
        'App\Modules\Payment\Application\Contract',
    ])
    ->toBeInterfaces();
