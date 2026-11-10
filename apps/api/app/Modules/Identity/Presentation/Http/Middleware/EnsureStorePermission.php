<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Middleware;

use App\Modules\Identity\Application\Contract\ChecksStorePermissions;
use App\Modules\Store\Application\StoreContext;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The store-scoped authorization convention (CLAUDE.md §8, §14).
 *
 * Applied to admin routes as:
 *
 *     Route::post('/admin/payments/{payment}/confirm', ...)
 *         ->middleware(['auth:sanctum', 'store.resolve', 'store.can:payment.confirm']);
 *
 * It enforces all three conditions together. Dropping any one of them is a
 * privilege-escalation bug:
 *
 *   1. authenticated          - otherwise there is no subject to authorize;
 *   2. store context resolved - otherwise there is no object to authorize against;
 *   3. permission in that store - role alone is not enough; the grant has to
 *      apply to this store.
 */
final class EnsureStorePermission
{
    public function __construct(
        private readonly ChecksStorePermissions $permissions,
        private readonly StoreContext $storeContext,
    ) {}

    public function handle(Request $request, Closure $next, string ...$required): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new BusinessRuleViolation(
                ErrorCode::Unauthenticated,
                'Authentication required',
            );
        }

        if (! $this->storeContext->has()) {
            // A programming error, not a client error: the route is missing the
            // store.resolve middleware. Surfacing it as 400 rather than failing
            // open is deliberate.
            throw new BusinessRuleViolation(
                ErrorCode::InvalidStoreContext,
                'This endpoint requires a store context',
            );
        }

        $storeId = $this->storeContext->require()->id;

        foreach ($required as $permission) {
            if (! $this->permissions->allows($user, $permission, $storeId)) {
                // The same answer whether the user lacks the permission or is
                // not a member at all, so the response cannot be used to probe
                // which stores exist or who staffs them.
                throw BusinessRuleViolation::storeScopeDenied();
            }
        }

        return $next($request);
    }
}
