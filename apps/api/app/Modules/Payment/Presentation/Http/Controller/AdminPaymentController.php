<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Controller;

use App\Modules\Payment\Application\Action\ConfirmPayment;
use App\Modules\Payment\Application\Action\FlagPayment;
use App\Modules\Payment\Domain\Contract\PaymentRepository;
use App\Modules\Payment\Domain\Entity\Payment;
use App\Modules\Payment\Presentation\Http\Request\ConfirmPaymentRequest;
use App\Modules\Payment\Presentation\Http\Request\FlagPaymentRequest;
use App\Modules\Payment\Presentation\Http\Request\ListAdminPaymentsRequest;
use App\Modules\Payment\Presentation\Http\Resource\AdminPaymentResource;
use App\Modules\Store\Application\StoreContext;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\Controllers\Controller;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Admin payment endpoints: the reconciliation queue.
 *
 * Thin by design (CLAUDE.md section 3): authorize, hand the validated input to
 * a use case, shape the answer with a Resource. The store always comes from
 * StoreContext, which `store.resolve` fills from the route or the host - never
 * from a query parameter a caller controls (section 8).
 */
final class AdminPaymentController extends Controller
{
    /**
     * StoreContext arrives per call, not in the constructor: Laravel memoises
     * the controller on the Route object, which outlives a request in a
     * long-lived process, and a request-scoped dependency held here would
     * outlive the request that resolved it.
     */
    public function __construct(private readonly PaymentRepository $payments) {}

    public function index(ListAdminPaymentsRequest $request, StoreContext $storeContext): JsonResponse
    {
        Gate::authorize('viewAny', Payment::class);

        $page = $this->payments->paginate($storeContext->require()->id, $request->toCriteria());

        return new JsonResponse([
            'items' => AdminPaymentResource::collection($page['items'])->resolve(),
            'next_cursor' => $page['next_cursor'],
            'has_more' => $page['has_more'],
        ]);
    }

    public function show(string $payment, StoreContext $storeContext): JsonResponse
    {
        $storeId = $storeContext->require()->id;
        $found = $this->payments->findInStore($storeId, $payment);

        if ($found === null) {
            // A payment of another store reads as missing: confirming the id
            // exists elsewhere would be an enumeration oracle (section 8).
            throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Payment not found');
        }

        Gate::authorize('view', $found);

        return (new AdminPaymentResource($found))->response();
    }

    public function confirm(
        ConfirmPaymentRequest $request,
        string $payment,
        StoreContext $storeContext,
        ConfirmPayment $confirmPayment,
    ): JsonResponse {
        $storeId = $storeContext->require()->id;
        $existing = $this->payments->findInStore($storeId, $payment);

        if ($existing === null) {
            throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Payment not found');
        }

        Gate::authorize('confirm', $existing);

        $updated = $confirmPayment->handle($storeId, $payment, $request->toInput(), $this->actorId($request));

        return (new AdminPaymentResource($updated))->response();
    }

    public function flag(
        FlagPaymentRequest $request,
        string $payment,
        StoreContext $storeContext,
        FlagPayment $flagPayment,
    ): JsonResponse {
        $storeId = $storeContext->require()->id;
        $existing = $this->payments->findInStore($storeId, $payment);

        if ($existing === null) {
            throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Payment not found');
        }

        Gate::authorize('flag', $existing);

        $updated = $flagPayment->handle($storeId, $payment, $request->toInput(), $this->actorId($request));

        return (new AdminPaymentResource($updated))->response();
    }

    /** The signed-in staff member, for the audit trail and the event history. */
    private function actorId(ConfirmPaymentRequest|FlagPaymentRequest $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        if (! is_string($id)) {
            // Unreachable behind auth:sanctum, which the route always applies;
            // failing loudly here beats recording a confirmation with no one
            // accountable for it.
            throw new BusinessRuleViolation(ErrorCode::Unauthenticated, 'Authentication required');
        }

        return $id;
    }
}
