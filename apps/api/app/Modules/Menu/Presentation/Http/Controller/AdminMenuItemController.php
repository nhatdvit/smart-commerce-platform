<?php

declare(strict_types=1);

namespace App\Modules\Menu\Presentation\Http\Controller;

use App\Modules\Catalog\Application\Contract\ProvidesVariantSnapshots;
use App\Modules\Menu\Application\Action\UpdateMenuItemAvailability;
use App\Modules\Menu\Application\DTO\MenuItemView;
use App\Modules\Menu\Domain\Entity\Menu;
use App\Modules\Menu\Presentation\Http\Request\UpdateMenuItemAvailabilityRequest;
use App\Modules\Menu\Presentation\Http\Resource\MenuItemResource;
use App\Modules\Store\Application\StoreContext;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\Controllers\Controller;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Sold-out and portion updates for one menu item.
 *
 * Its own endpoint rather than part of the menu replacement, because running
 * out of an item mid-service is a single operational fact and should cost one
 * request and one permission (CLAUDE.md section 6).
 */
final class AdminMenuItemController extends Controller
{
    /**
     * StoreContext arrives per call, not in the constructor: Laravel memoises
     * the controller on the Route object, so a request-scoped dependency held
     * here would outlive the request that resolved it.
     */
    public function __construct(private readonly ProvidesVariantSnapshots $catalog) {}

    public function update(
        UpdateMenuItemAvailabilityRequest $request,
        string $menuItem,
        StoreContext $storeContext,
        UpdateMenuItemAvailability $updateAvailability,
    ): JsonResponse {
        Gate::authorize('updateAvailability', Menu::class);

        $storeId = $storeContext->require()->id;

        $item = $updateAvailability->handle(
            $storeId,
            $menuItem,
            $request->isAvailable(),
            $request->quantityGiven(),
            $request->availableQuantity(),
            $request->capacityGiven(),
            $request->dailyCapacity(),
        );

        $snapshots = $this->catalog->forStore($storeId, [$item->variantId]);
        $snapshot = $snapshots[$item->variantId] ?? null;

        if ($snapshot === null) {
            // The composite foreign key makes this unreachable: a live item
            // cannot reference a variant from another store, or none at all.
            throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Menu item not found');
        }

        return (new MenuItemResource(new MenuItemView($item, $snapshot)))->response();
    }
}
