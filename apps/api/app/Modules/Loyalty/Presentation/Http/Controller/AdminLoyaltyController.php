<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Http\Controller;

use App\Modules\Loyalty\Application\Action\RedeemReward;
use App\Modules\Loyalty\Application\Action\UpdateLoyaltySettings;
use App\Modules\Loyalty\Domain\Contract\LoyaltyLedger;
use App\Modules\Loyalty\Domain\Contract\LoyaltySettingsRepository;
use App\Modules\Loyalty\Domain\Entity\Reward;
use App\Modules\Loyalty\Presentation\Http\Request\ListMembersRequest;
use App\Modules\Loyalty\Presentation\Http\Request\RedeemRewardRequest;
use App\Modules\Loyalty\Presentation\Http\Request\UpdateLoyaltySettingsRequest;
use App\Modules\Loyalty\Presentation\Http\Resource\LedgerEntryResource;
use App\Modules\Loyalty\Presentation\Http\Resource\LoyaltyMemberResource;
use App\Modules\Loyalty\Presentation\Http\Resource\LoyaltySettingsResource;
use App\Modules\Store\Application\StoreContext;
use App\Shared\Presentation\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Admin loyalty endpoints: the dashboard, the member directory and the
 * earning policy. Reward CRUD lives in {@see AdminRewardController} - a
 * separate catalogue with its own lifecycle, the same split Pricing draws
 * between a coupon's definition and everything else on that screen.
 *
 * Thin by design (CLAUDE.md section 3). The store always comes from
 * StoreContext, never from the payload.
 */
final class AdminLoyaltyController extends Controller
{
    public function __construct(
        private readonly LoyaltyLedger $ledger,
        private readonly LoyaltySettingsRepository $settings,
    ) {}

    /** Dashboard tiles: how many members, how many points issued, how much was redeemed this month. */
    public function summary(StoreContext $storeContext): JsonResponse
    {
        Gate::authorize('viewAny', Reward::class);

        return new JsonResponse($this->ledger->summary($storeContext->require()->id));
    }

    public function members(ListMembersRequest $request, StoreContext $storeContext): JsonResponse
    {
        Gate::authorize('viewAny', Reward::class);

        $page = $this->ledger->paginateMembers($storeContext->require()->id, $request->toCriteria());

        return new JsonResponse([
            'items' => LoyaltyMemberResource::collection($page['items'])->resolve(),
            'next_cursor' => $page['next_cursor'],
            'has_more' => $page['has_more'],
        ]);
    }

    /** One member's standing and their full history, most recent first. */
    public function show(string $phone, StoreContext $storeContext): JsonResponse
    {
        Gate::authorize('viewAny', Reward::class);

        $storeId = $storeContext->require()->id;
        $balance = $this->ledger->balanceOf($storeId, $phone);
        $history = $this->ledger->historyFor($storeId, $phone);

        return new JsonResponse([
            'phone' => $phone,
            'lifetime_earned_points' => $balance['lifetimeEarnedPoints'],
            'balance' => $balance['balance'],
            'history' => LedgerEntryResource::collection($history)->resolve(),
        ]);
    }

    public function redeem(
        RedeemRewardRequest $request,
        string $phone,
        StoreContext $storeContext,
        RedeemReward $redeemReward,
    ): JsonResponse {
        Gate::authorize('redeem', Reward::class);

        $entry = $redeemReward->handle(
            $storeContext->require()->id,
            $phone,
            $request->rewardId(),
            $this->actorId($request),
            $request->note(),
        );

        return (new LedgerEntryResource($entry))->response()->setStatusCode(201);
    }

    public function settings(StoreContext $storeContext): JsonResponse
    {
        Gate::authorize('viewAny', Reward::class);

        return (new LoyaltySettingsResource(
            $this->settings->forStore($storeContext->require()->id),
        ))->response();
    }

    public function updateSettings(
        UpdateLoyaltySettingsRequest $request,
        StoreContext $storeContext,
        UpdateLoyaltySettings $updateSettings,
    ): JsonResponse {
        Gate::authorize('manageSettings', Reward::class);

        $updated = $updateSettings->handle(
            $storeContext->require()->id,
            $request->pointsPerCurrency(),
            $request->loyalTierThresholdPoints(),
            $this->actorId($request),
        );

        return (new LoyaltySettingsResource($updated))->response();
    }

    /** The signed-in staff member, required here: redeeming and configuring are never anonymous. */
    private function actorId(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        if (! is_string($id)) {
            throw new RuntimeException('Authenticated user has no string identifier');
        }

        return $id;
    }
}
