<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Http\Controller;

use App\Modules\Identity\Infrastructure\Persistence\Model\User;
use App\Modules\Loyalty\Domain\Contract\LoyaltyLedger;
use App\Modules\Loyalty\Domain\Contract\LoyaltySettingsRepository;
use App\Modules\Loyalty\Domain\Enum\MembershipTier;
use App\Modules\Store\Application\StoreContext;
use App\Shared\Presentation\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A signed-in customer's own standing in the current store's loyalty
 * program - the same ledger {@see AdminLoyaltyController::show()} reads,
 * scoped to the caller's own phone instead of one taken from the URL. A
 * customer must never be able to ask for anyone else's balance.
 */
final class CustomerLoyaltyController extends Controller
{
    public function __construct(
        private readonly LoyaltyLedger $ledger,
        private readonly LoyaltySettingsRepository $settings,
    ) {}

    public function show(Request $request, StoreContext $storeContext): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User || $user->phone === null) {
            // Every customer account is created with a phone number
            // (`RegisterRequest`), so this would mean a staff session reached
            // a customer-only route - a routing bug, not a real user state.
            throw new RuntimeException('Authenticated customer has no phone number');
        }

        $storeId = $storeContext->require()->id;
        $balance = $this->ledger->balanceOf($storeId, $user->phone);
        $tier = MembershipTier::forLifetimeEarnedPoints(
            $balance['lifetimeEarnedPoints'],
            $this->settings->forStore($storeId)->loyalTierThresholdPoints,
        );

        return new JsonResponse([
            'phone' => $user->phone,
            'lifetime_earned_points' => $balance['lifetimeEarnedPoints'],
            'balance' => $balance['balance'],
            'tier' => $tier->value,
            'tier_label' => $tier->label(),
        ]);
    }
}
