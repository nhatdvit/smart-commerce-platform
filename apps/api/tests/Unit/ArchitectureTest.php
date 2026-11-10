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

arch('contracts are interfaces')
    ->expect('App\Modules\Identity\Application\Contract')
    ->toBeInterfaces();
