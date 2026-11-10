<?php

declare(strict_types=1);

namespace App\Modules\Store;

use App\Modules\Store\Application\StoreContext;
use Illuminate\Support\ServiceProvider;

/**
 * Store module wiring.
 */
final class StoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One resolved store per request. Scoped rather than singleton so a
        // queue worker handling many jobs never reuses a stale store.
        $this->app->scoped(StoreContext::class);
    }
}
