<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Modules\Catalog\Application\Contract\ProvidesVariantSnapshots;
use App\Modules\Catalog\Domain\Contract\CategoryRepository;
use App\Modules\Catalog\Domain\Contract\ProductImageStorage;
use App\Modules\Catalog\Domain\Contract\ProductRepository;
use App\Modules\Catalog\Domain\Entity\Product;
use App\Modules\Catalog\Infrastructure\Persistence\EloquentCategoryRepository;
use App\Modules\Catalog\Infrastructure\Persistence\EloquentProductRepository;
use App\Modules\Catalog\Infrastructure\Persistence\EloquentVariantSnapshotProvider;
use App\Modules\Catalog\Infrastructure\Storage\DiskProductImageStorage;
use App\Modules\Catalog\Presentation\Console\PruneProductImagesCommand;
use App\Modules\Catalog\Presentation\Policy\ProductPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

/**
 * Catalog module wiring.
 *
 * Registered explicitly in bootstrap/providers.php rather than auto-discovered,
 * so the set of active modules stays one readable list (CLAUDE.md section 4).
 */
final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Stateless repositories: one instance per container is enough.
        $this->app->singleton(ProductRepository::class, EloquentProductRepository::class);
        $this->app->singleton(CategoryRepository::class, EloquentCategoryRepository::class);

        // The module's exported read model. Other modules bind to the
        // interface; nothing outside Catalog touches its tables.
        $this->app->singleton(ProvidesVariantSnapshots::class, EloquentVariantSnapshotProvider::class);

        // Which disk holds product photography is a deployment decision, so
        // it is resolved from configuration here and nowhere else: the API's
        // own disk now, object storage when one is configured.
        $this->app->singleton(
            ProductImageStorage::class,
            static fn (): DiskProductImageStorage => new DiskProductImageStorage(
                Storage::disk((string) config('commerce.media.disk', 'local')),
            ),
        );
    }

    public function boot(): void
    {
        // The policy guards the DOMAIN product, not the Eloquent row, so
        // authorization is expressed against the business object.
        Gate::policy(Product::class, ProductPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PruneProductImagesCommand::class]);
        }
    }
}
