<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Identity\Application\Contract\ChecksStorePermissions;
use App\Modules\Identity\Application\StorePermissionChecker;
use Illuminate\Support\ServiceProvider;

/**
 * Identity module wiring.
 *
 * Modules are registered explicitly in bootstrap/providers.php rather than
 * auto-discovered, so the set of active modules is one readable list
 * (CLAUDE.md section 4).
 */
final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, not singleton: the permission cache inside the checker is
        // valid for one request only. In a long-lived worker a singleton would
        // leak one user permissions into the next job.
        $this->app->scoped(ChecksStorePermissions::class, StorePermissionChecker::class);
    }
}
