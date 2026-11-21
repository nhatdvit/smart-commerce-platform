<?php

declare(strict_types=1);

namespace App\Modules\Store\Presentation\Http\Controller;

use App\Modules\Store\Application\StoreContext;
use App\Shared\Presentation\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * "Which store is this session in" - for an admin screen that needs the id in
 * a URL, rather than for the request itself.
 *
 * Every other admin route resolves its store silently through
 * {@see \App\Modules\Store\Presentation\Http\Middleware\ResolveStoreContext}
 * and never needs the id on the client; the two payment-settings endpoints
 * are the one place CLAUDE.md section 8's own example puts `{store}` in the
 * path, because changing where money lands is exactly the action that should
 * not be reachable by host or default alone. This read is what lets the
 * dashboard build that path without the operator having to type a store id
 * anywhere. No permission beyond authentication: a store's name and slug are
 * not sensitive, and every admin screen already discloses which store it is
 * scoped to simply by what it shows.
 */
final class AdminStoreContextController extends Controller
{
    public function show(StoreContext $storeContext): JsonResponse
    {
        $store = $storeContext->require();

        return new JsonResponse([
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'timezone' => $store->timezone,
        ]);
    }
}
