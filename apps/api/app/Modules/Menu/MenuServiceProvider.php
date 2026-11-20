<?php

declare(strict_types=1);

namespace App\Modules\Menu;

use App\Modules\Menu\Domain\Contract\MenuRepository;
use App\Modules\Menu\Domain\Entity\Menu;
use App\Modules\Menu\Infrastructure\Persistence\EloquentMenuRepository;
use App\Modules\Menu\Presentation\Policy\MenuPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Menu module wiring.
 *
 * The module depends on Catalog only through
 * `Catalog\Application\Contract\ProvidesVariantSnapshots`, which Catalog's own
 * provider binds. Nothing here reaches into the catalog's tables (CLAUDE.md
 * section 3).
 */
final class MenuServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MenuRepository::class, EloquentMenuRepository::class);
    }

    public function boot(): void
    {
        Gate::policy(Menu::class, MenuPolicy::class);
    }
}
