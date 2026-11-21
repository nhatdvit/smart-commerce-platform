<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Presentation\Http\Controller;

use App\Modules\Pricing\Application\Action\CreateCoupon;
use App\Modules\Pricing\Application\Action\UpdateCoupon;
use App\Modules\Pricing\Domain\Contract\CouponRepository;
use App\Modules\Pricing\Domain\Entity\Coupon;
use App\Modules\Pricing\Presentation\Http\Request\ListCouponsRequest;
use App\Modules\Pricing\Presentation\Http\Request\SaveCouponRequest;
use App\Modules\Pricing\Presentation\Http\Resource\CouponResource;
use App\Modules\Store\Application\StoreContext;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\Controllers\Controller;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Admin coupon endpoints.
 *
 * Thin by design (CLAUDE.md section 3): authorize, hand the validated input to
 * a use case, shape the answer with a Resource. The store comes from
 * StoreContext, never from the payload.
 */
final class AdminCouponController extends Controller
{
    /**
     * Only stateless collaborators are injected here; StoreContext is scoped
     * to one request while Laravel memoises the controller on the Route
     * object, so it arrives as a method argument instead.
     */
    public function __construct(private readonly CouponRepository $coupons) {}

    public function index(ListCouponsRequest $request, StoreContext $storeContext): JsonResponse
    {
        Gate::authorize('viewAny', Coupon::class);

        $page = $this->coupons->paginate($storeContext->require()->id, $request->toCriteria());

        return new JsonResponse([
            'items' => CouponResource::collection($page['items'])->resolve(),
            'next_cursor' => $page['next_cursor'],
            'has_more' => $page['has_more'],
        ]);
    }

    public function store(
        SaveCouponRequest $request,
        StoreContext $storeContext,
        CreateCoupon $createCoupon,
    ): JsonResponse {
        Gate::authorize('create', Coupon::class);

        $coupon = $createCoupon->handle($storeContext->require()->id, $request->toInput());

        return (new CouponResource($coupon))->response()->setStatusCode(201);
    }

    public function update(
        SaveCouponRequest $request,
        string $coupon,
        StoreContext $storeContext,
        UpdateCoupon $updateCoupon,
    ): JsonResponse {
        $storeId = $storeContext->require()->id;
        $existing = $this->coupons->findInStore($storeId, $coupon);

        if ($existing === null) {
            // A coupon of another store reads as missing: confirming the id
            // exists elsewhere would be an enumeration oracle (section 8).
            throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Coupon not found');
        }

        Gate::authorize('update', $existing);

        return (new CouponResource(
            $updateCoupon->handle($storeId, $coupon, $request->toInput()),
        ))->response();
    }
}
