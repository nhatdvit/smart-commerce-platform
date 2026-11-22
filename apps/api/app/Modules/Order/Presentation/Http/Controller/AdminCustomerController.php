<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Http\Controller;

use App\Modules\Order\Domain\Contract\CustomerDirectory;
use App\Modules\Order\Domain\Entity\Order;
use App\Modules\Order\Presentation\Http\Request\ListCustomersRequest;
use App\Modules\Order\Presentation\Http\Resource\CustomerSummaryResource;
use App\Modules\Store\Application\StoreContext;
use App\Shared\Presentation\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Admin customer directory: guest order history, grouped by phone.
 *
 * Thin by design (CLAUDE.md section 3). Gated by the same `viewAny` ability as
 * the order queue - reading this is reading orders, only grouped
 * differently, and there is no dedicated Customer module yet to give it a
 * permission of its own (CLAUDE.md section 5 still lists one as future work).
 */
final class AdminCustomerController extends Controller
{
    public function __construct(private readonly CustomerDirectory $customers) {}

    public function index(ListCustomersRequest $request, StoreContext $storeContext): JsonResponse
    {
        Gate::authorize('viewAny', Order::class);

        $page = $this->customers->paginate($storeContext->require()->id, $request->toCriteria());

        return new JsonResponse([
            'items' => CustomerSummaryResource::collection($page['items'])->resolve(),
            'next_cursor' => $page['next_cursor'],
            'has_more' => $page['has_more'],
        ]);
    }
}
