<?php

declare(strict_types=1);

namespace App\Modules\Menu\Presentation\Http\Controller;

use App\Modules\Menu\Application\ComposeTodayMenu;
use App\Modules\Menu\Presentation\Http\Resource\MenuItemResource;
use App\Modules\Store\Application\StoreContext;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\Controllers\Controller;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Http\JsonResponse;

/**
 * The customer-facing menu: no authentication, no permission check - anyone
 * may see what a store is selling. `store.resolve` still runs, because the
 * store itself is never read from anything the caller supplies untrusted
 * (CLAUDE.md section 8).
 *
 * Shaped to the designed `TodayMenu` contract (`docs/api/openapi.yaml`), minus
 * the schedule fields that contract reserves for the slice that builds
 * recurring windows and date exceptions: `served_from`/`served_until` are
 * always null and there is no `preorder` object, because neither exists yet
 * (see the note on `ComposeTodayMenu`).
 */
final class PublicMenuController extends Controller
{
    public function __construct(private readonly ComposeTodayMenu $menu) {}

    public function today(StoreContext $storeContext): JsonResponse
    {
        $store = $storeContext->require();
        $view = $this->menu->forStore($store->id);

        if ($view === null) {
            throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'No menu is currently available');
        }

        $serviceDate = now()->setTimezone($store->timezone)->toDateString();

        return new JsonResponse([
            'store_id' => $store->id,
            'menu_id' => $view->menu->id,
            'name' => $view->menu->name,
            'service_date' => $serviceDate,
            'timezone' => $store->timezone,
            'served_from' => null,
            'served_until' => null,
            'items' => MenuItemResource::collection($view->items)->resolve(),
        ]);
    }
}
