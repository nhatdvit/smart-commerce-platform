<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Controller;

use App\Modules\Identity\Infrastructure\Persistence\Model\StoreMember;
use App\Modules\Identity\Infrastructure\Persistence\Model\User;
use App\Modules\Identity\Presentation\Http\Request\LoginRequest;
use App\Modules\Identity\Presentation\Http\Resource\CurrentUserResource;
use App\Modules\Store\Application\StoreDirectory;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\Controllers\Controller;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Session lifecycle for the first-party web apps.
 *
 * Cookie authentication, not tokens: the customer and admin apps are
 * first-party, so Sanctum's stateful SPA flow applies and no access token ever
 * reaches JavaScript (CLAUDE.md section 8). The client calls
 * `GET /sanctum/csrf-cookie` first, then posts here with credentials.
 *
 * Rate limited per credential AND per IP by the `login` limiter, so neither one
 * account nor one host can be brute-forced cheaply.
 */
final class SessionController extends Controller
{
    public function __construct(private readonly StoreDirectory $stores) {}

    public function login(LoginRequest $request): JsonResponse
    {
        if (! $request->hasSession()) {
            // Sanctum only starts a session for a request whose origin is a
            // configured stateful domain, so landing here means the caller is
            // not one of the first-party apps - a wrong Origin, or a host that
            // is missing from SANCTUM_STATEFUL_DOMAINS. Saying so beats the 500
            // that reaching for a session store would otherwise produce.
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'Cookie authentication is only available to the first-party web apps',
            );
        }

        // Laravel's session guard verifies the hash and regenerates nothing by
        // itself; the regenerate below is what prevents session fixation.
        if (! Auth::guard('web')->attempt($request->credentials(), $request->remember())) {
            // One answer for a wrong password and for an unknown address/
            // number: the response must not reveal which ones have accounts.
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'These credentials do not match our records',
                [['field' => $request->identityField(), 'message' => $this->invalidCredentialsMessage($request)]],
            );
        }

        $request->session()->regenerate();

        $user = Auth::guard('web')->user();

        if (! $user instanceof User) {
            throw new BusinessRuleViolation(ErrorCode::InternalError, 'Authentication failed');
        }

        if (! $user->status->canAuthenticate()) {
            // A blocked account must not hold a session, and the session the
            // attempt just created has to go with it.
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'These credentials do not match our records',
                [['field' => $request->identityField(), 'message' => $this->invalidCredentialsMessage($request)]],
            );
        }

        // Relations first, then the store names they name: the resource reads
        // both and the order of two arguments is a poor place to hide that.
        $user->load(['globalRoles', 'storeMemberships.role']);

        return (new CurrentUserResource($user, $this->storeNames($user)))->response();
    }

    /** Idempotent: calling it twice, or with a session already gone, is not an error. */
    public function logout(Request $request): JsonResponse
    {
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return new JsonResponse(status: 204);
    }

    private function invalidCredentialsMessage(LoginRequest $request): string
    {
        return $request->identityField() === 'phone'
            ? 'Số điện thoại hoặc mật khẩu không đúng.'
            : 'Email hoặc mật khẩu không đúng.';
    }

    /** @return array<string, string> */
    private function storeNames(User $user): array
    {
        return $this->stores->namesByIds(
            array_values($user->storeMemberships
                ->map(static fn (StoreMember $membership): string => $membership->store_id)
                ->all()),
        );
    }
}
