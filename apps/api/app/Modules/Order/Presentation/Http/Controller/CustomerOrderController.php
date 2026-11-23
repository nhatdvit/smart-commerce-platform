<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Http\Controller;

use App\Modules\Order\Application\Action\CancelOrder;
use App\Modules\Order\Application\Action\CreateOrder;
use App\Modules\Order\Application\ComposeOrderView;
use App\Modules\Order\Domain\Contract\OrderRepository;
use App\Modules\Order\Domain\Entity\Order;
use App\Modules\Order\Infrastructure\Persistence\Model\Order as OrderModel;
use App\Modules\Order\Presentation\Http\Request\CancelOrderRequest;
use App\Modules\Order\Presentation\Http\Request\CreateOrderRequest;
use App\Modules\Order\Presentation\Http\Request\ListMyOrdersRequest;
use App\Modules\Order\Presentation\Http\Resource\OrderPaymentResource;
use App\Modules\Order\Presentation\Http\Resource\OrderResource;
use App\Modules\Order\Presentation\Http\Resource\OrderSummaryResource;
use App\Modules\Payment\Application\Contract\ProvidesOrderPaymentDetails;
use App\Modules\Store\Application\StoreContext;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\Controllers\Controller;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Checkout and the two reads a customer needs afterwards: the order itself,
 * and how to pay it.
 *
 * Reachable by a guest or a signed-in customer alike - CLAUDE.md section 6
 * requires guest checkout, so none of this carries `auth:sanctum`. A
 * signed-in customer is read from the session when one is present
 * ({@see authenticatedCustomerId()}); a guest proves ownership with the
 * one-time token `store()` hands back instead (CLAUDE.md section 8: "do not
 * expose orders by guessable ID alone").
 */
final class CustomerOrderController extends Controller
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly ComposeOrderView $view,
        private readonly ProvidesOrderPaymentDetails $paymentDetails,
    ) {}

    public function store(CreateOrderRequest $request, StoreContext $storeContext, CreateOrder $createOrder): JsonResponse
    {
        $storeId = $storeContext->require()->id;

        $result = $createOrder->handle($storeId, $request->toInput(), $this->authenticatedCustomerId($request));

        $found = $this->orders->findInStore($storeId, $result->orderId);
        $payment = $this->paymentDetails->forOrder($storeId, $result->orderId);

        if ($found === null || $payment === null) {
            throw new RuntimeException('Order or payment vanished immediately after being created');
        }

        // The designed `OrderCreated` shape: the order, its payment
        // instructions (a bank-transfer customer needs the VietQR right
        // away, not a second round trip for it), and - the only time this
        // ever appears - a guest's one-time key to read the order back.
        // There is no "forgot it" recovery, by design.
        return new JsonResponse([
            'order' => (new OrderResource($this->view->forOrder($storeId, $found)))->resolve(),
            'payment' => (new OrderPaymentResource($payment, $found->fulfillmentType))->resolve(),
            'guest_order_token' => $result->guestAccessToken,
        ], 201);
    }

    /**
     * A signed-in customer's own order history, newest first.
     *
     * `auth:sanctum` on the route guarantees a customer id - a guest has no
     * durable identity to list orders by, only the one-time token for the
     * single order they just placed.
     */
    public function mine(ListMyOrdersRequest $request, StoreContext $storeContext): JsonResponse
    {
        $storeId = $storeContext->require()->id;
        $customerId = $this->authenticatedCustomerId($request);

        if ($customerId === null) {
            throw new RuntimeException('auth:sanctum let an unauthenticated request reach mine()');
        }

        $page = $this->orders->paginateForCustomer($storeId, $customerId, $request->toCriteria());

        return new JsonResponse([
            'items' => OrderSummaryResource::collection(
                $this->view->forOrders($storeId, $page['items']),
            )->resolve(),
            'next_cursor' => $page['next_cursor'],
            'has_more' => $page['has_more'],
        ]);
    }

    public function show(string $order, Request $request, StoreContext $storeContext): JsonResponse
    {
        $storeId = $storeContext->require()->id;
        $found = $this->findAuthorized($order, $request, $storeId);

        return (new OrderResource($this->view->forOrder($storeId, $found)))->response();
    }

    public function payment(string $order, Request $request, StoreContext $storeContext): JsonResponse
    {
        $storeId = $storeContext->require()->id;
        $found = $this->findAuthorized($order, $request, $storeId);

        $payment = $this->paymentDetails->forOrder($storeId, $order);

        if ($payment === null) {
            // A real anomaly - checkout always creates one alongside the
            // order - not a reason to pretend the order itself is missing.
            throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Payment not found');
        }

        return (new OrderPaymentResource($payment, $found->fulfillmentType))->response();
    }

    /**
     * A customer or guest cancelling their own order - before the kitchen has
     * started, by the rule {@see CancelOrder} enforces.
     */
    public function cancel(
        string $order,
        CancelOrderRequest $request,
        StoreContext $storeContext,
        CancelOrder $cancelOrder,
    ): JsonResponse {
        $storeId = $storeContext->require()->id;
        // Ownership is checked the same way a read is - owner session or the
        // matching guest token - before the cancel action ever locks the row.
        $this->findAuthorized($order, $request, $storeId);

        $cancelled = $cancelOrder->handle(
            $storeId,
            $order,
            $this->authenticatedCustomerId($request),
            $request->reason(),
        );

        return (new OrderResource($this->view->forOrder($storeId, $cancelled)))->response();
    }

    private function authenticatedCustomerId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $id : null;
    }

    private function findAuthorized(string $orderId, Request $request, string $storeId): Order
    {
        $found = $this->orders->findInStore($storeId, $orderId);

        if ($found === null) {
            throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Order not found');
        }

        $customerId = $this->authenticatedCustomerId($request);
        $guestToken = $request->header('X-Guest-Order-Token');

        if ($customerId !== null && $found->customerId === $customerId) {
            return $found;
        }

        if (is_string($guestToken) && $guestToken !== '' && $this->hasValidGuestToken($found, $guestToken)) {
            return $found;
        }

        // No credential at all: say so plainly. A credential that was given
        // but does not match this order answers the same as "not found" -
        // confirming the id belongs to someone else would be an
        // enumeration oracle (CLAUDE.md section 8), the same reasoning
        // AdminOrderController::show applies to another store's order.
        if ($customerId === null && $guestToken === null) {
            throw new BusinessRuleViolation(ErrorCode::Unauthenticated, 'Sign in or provide the guest order token');
        }

        throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Order not found');
    }

    /**
     * Only the hash is ever compared (CLAUDE.md section 8), the same
     * convention `auth_sessions.refresh_token_hash` already uses - an
     * indexed equality lookup on the hash, never the plain token.
     */
    private function hasValidGuestToken(Order $order, string $token): bool
    {
        return OrderModel::query()
            ->where('id', $order->id)
            ->where('store_id', $order->storeId)
            ->where('guest_access_token_hash', hash('sha256', $token))
            ->exists();
    }
}
