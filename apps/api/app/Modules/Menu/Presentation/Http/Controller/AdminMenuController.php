<?php

declare(strict_types=1);

namespace App\Modules\Menu\Presentation\Http\Controller;

use App\Modules\Menu\Application\Action\ReplaceMenu;
use App\Modules\Menu\Application\ComposeMenuView;
use App\Modules\Menu\Domain\Contract\MenuRepository;
use App\Modules\Menu\Domain\Entity\Menu;
use App\Modules\Menu\Presentation\Http\Request\ReplaceMenuRequest;
use App\Modules\Menu\Presentation\Http\Resource\MenuResource;
use App\Modules\Store\Application\StoreContext;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\Controllers\Controller;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Admin menu endpoints.
 *
 * Thin: authorize, delegate to a use case, shape with a Resource (CLAUDE.md
 * section 3). The store always comes from StoreContext, never from the payload.
 */
final class AdminMenuController extends Controller
{
    /**
     * Only stateless collaborators are injected here.
     *
     * StoreContext is scoped to one request while Laravel memoises the
     * controller on the Route object, which outlives a request in a long-lived
     * process. Taking it as a method argument keeps the store this request
     * resolved from leaking into the next one.
     */
    public function __construct(
        private readonly MenuRepository $menus,
        private readonly ComposeMenuView $view,
    ) {}

    /**
     * Menus for this store.
     *
     * A store has a handful of menus, so every one is returned, inside the
     * designed page envelope: the shape stays the documented one even where
     * pagination has nothing to do.
     */
    public function index(StoreContext $storeContext): JsonResponse
    {
        Gate::authorize('viewAny', Menu::class);

        $storeId = $storeContext->require()->id;
        $views = $this->view->forMenus($storeId, $this->menus->allInStore($storeId));

        return new JsonResponse([
            'items' => MenuResource::collection($views)->resolve(),
            'next_cursor' => null,
            'has_more' => false,
        ]);
    }

    public function show(string $menu, StoreContext $storeContext): JsonResponse
    {
        $storeId = $storeContext->require()->id;
        $found = $this->menus->findInStore($storeId, $menu);

        if ($found === null) {
            throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Menu not found');
        }

        Gate::authorize('view', $found);

        return (new MenuResource($this->view->forMenu($storeId, $found)))->response();
    }

    public function update(
        ReplaceMenuRequest $request,
        string $menu,
        StoreContext $storeContext,
        ReplaceMenu $replaceMenu,
    ): JsonResponse {
        $storeId = $storeContext->require()->id;
        $existing = $this->menus->findInStore($storeId, $menu);

        if ($existing === null) {
            // A menu of another store reads as missing: its existence is not
            // this store's information (CLAUDE.md section 8).
            throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Menu not found');
        }

        Gate::authorize('update', $existing);

        $updated = $replaceMenu->handle($storeId, $menu, $request->toInput());

        return (new MenuResource($this->view->forMenu($storeId, $updated)))->response();
    }
}
