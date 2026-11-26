<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Http\Controller;

use App\Modules\Order\Application\Action\TransitionOrder;
use App\Modules\Order\Application\ComposeOrderView;
use App\Modules\Order\Domain\Contract\OrderRepository;
use App\Modules\Order\Domain\Entity\Order;
use App\Modules\Order\Presentation\Http\Request\ListOrdersRequest;
use App\Modules\Order\Presentation\Http\Request\TransitionOrderRequest;
use App\Modules\Order\Presentation\Http\Resource\OrderResource;
use App\Modules\Order\Presentation\Http\Resource\OrderSummaryResource;
use App\Modules\Store\Application\StoreContext;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\Controllers\Controller;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Admin order endpoints: the operations queue.
 *
 * Thin by design (CLAUDE.md section 3): authorize, hand the validated input to
 * a use case, shape the answer with a Resource. The state machine is in the
 * domain and the transaction is in the action; neither belongs here.
 *
 * The store always comes from StoreContext, which the `store.resolve`
 * middleware fills from the route or the host - never from a query parameter a
 * caller controls (section 8).
 */
final class AdminOrderController extends Controller
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
        private readonly OrderRepository $orders,
        private readonly ComposeOrderView $view,
    ) {}

    /**
     * A page of this store's orders, oldest first by default.
     *
     * `status_counts` comes along because the screen that reads this is a work
     * queue: "how many orders are waiting" has to be the real number, not the
     * number that happens to be on the first page. Open statuses only, which
     * is what the partial index covers and what a queue is about.
     */
    public function index(ListOrdersRequest $request, StoreContext $storeContext): JsonResponse
    {
        Gate::authorize('viewAny', Order::class);

        $storeId = $storeContext->require()->id;
        $page = $this->orders->paginate($storeId, $request->toCriteria());

        return new JsonResponse([
            'items' => OrderSummaryResource::collection(
                $this->view->forOrders($storeId, $page['items']),
            )->resolve(),
            'next_cursor' => $page['next_cursor'],
            'has_more' => $page['has_more'],
            'status_counts' => $this->orders->openStatusCounts($storeId),
            'awaiting_payment_confirmation_count' => $this->view->countAwaitingPaymentConfirmation($storeId),
        ]);
    }

    public function show(string $order, StoreContext $storeContext): JsonResponse
    {
        $storeId = $storeContext->require()->id;
        $found = $this->orders->findInStore($storeId, $order);

        if ($found === null) {
            // An order of another store reads as missing: confirming that the
            // id exists elsewhere would be an enumeration oracle (section 8).
            throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Order not found');
        }

        Gate::authorize('view', $found);

        return (new OrderResource($this->view->forOrder($storeId, $found)))->response();
    }

    public function transition(
        TransitionOrderRequest $request,
        string $order,
        StoreContext $storeContext,
        TransitionOrder $transitionOrder,
    ): JsonResponse {
        $storeId = $storeContext->require()->id;
        $existing = $this->orders->findInStore($storeId, $order);

        if ($existing === null) {
            throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Order not found');
        }

        $to = $request->toStatus();

        // The object check, and the one that decides whether a cook may make
        // this particular move. The action then re-reads the order under a row
        // lock, so the status it validates against is the committed one.
        Gate::authorize('transition', [$existing, $to]);

        $updated = $transitionOrder->handle(
            $storeId,
            $order,
            $to,
            $request->reason(),
            $this->actorId($request),
        );

        return (new OrderResource($this->view->forOrder($storeId, $updated)))->response();
    }

    /** The signed-in staff member, for the status history and the audit trail. */
    private function actorId(TransitionOrderRequest $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }
}
