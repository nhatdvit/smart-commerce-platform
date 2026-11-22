<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Http\Controller;

use App\Modules\Loyalty\Application\Action\SaveReward;
use App\Modules\Loyalty\Domain\Contract\RewardRepository;
use App\Modules\Loyalty\Domain\Entity\Reward;
use App\Modules\Loyalty\Presentation\Http\Request\SaveRewardRequest;
use App\Modules\Loyalty\Presentation\Http\Resource\RewardResource;
use App\Modules\Store\Application\StoreContext;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\Controllers\Controller;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Admin reward endpoints: the redeemable-reward catalogue.
 *
 * Thin by design (CLAUDE.md section 3): authorize, hand the validated input
 * to a use case, shape the answer with a Resource.
 */
final class AdminRewardController extends Controller
{
    public function __construct(private readonly RewardRepository $rewards) {}

    public function index(StoreContext $storeContext): JsonResponse
    {
        Gate::authorize('viewAny', Reward::class);

        return new JsonResponse([
            'items' => RewardResource::collection(
                $this->rewards->allInStore($storeContext->require()->id),
            )->resolve(),
        ]);
    }

    public function store(SaveRewardRequest $request, StoreContext $storeContext, SaveReward $saveReward): JsonResponse
    {
        Gate::authorize('create', Reward::class);

        $reward = $saveReward->handle(
            $storeContext->require()->id,
            null,
            $request->toInput(),
            $this->actorId($request),
        );

        return (new RewardResource($reward))->response()->setStatusCode(201);
    }

    public function update(
        SaveRewardRequest $request,
        string $reward,
        StoreContext $storeContext,
        SaveReward $saveReward,
    ): JsonResponse {
        $storeId = $storeContext->require()->id;
        $existing = $this->rewards->findInStore($storeId, $reward);

        if ($existing === null) {
            // A reward of another store reads as missing: confirming the id
            // exists elsewhere would be an enumeration oracle (section 8).
            throw new BusinessRuleViolation(ErrorCode::ResourceNotFound, 'Reward not found');
        }

        Gate::authorize('update', $existing);

        return (new RewardResource(
            $saveReward->handle($storeId, $reward, $request->toInput(), $this->actorId($request)),
        ))->response();
    }

    private function actorId(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        if (! is_string($id)) {
            throw new RuntimeException('Authenticated user has no string identifier');
        }

        return $id;
    }
}
