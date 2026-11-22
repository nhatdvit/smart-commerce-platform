<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Controller;

use App\Modules\Identity\Application\Action\RegisterCustomerAccount;
use App\Modules\Identity\Presentation\Http\Request\RegisterRequest;
use App\Modules\Identity\Presentation\Http\Resource\CurrentUserResource;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\Controllers\Controller;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Customer self-registration, by phone - see `RegisterRequest`.
 *
 * Signs the new account straight in, exactly like `SessionController::login`:
 * the same cookie session, the same CSRF/stateful-domain guard, so a fresh
 * registration leaves the customer-web app in a signed-in state without a
 * second round trip.
 */
final class RegistrationController extends Controller
{
    public function __construct(private readonly RegisterCustomerAccount $register) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        if (! $request->hasSession()) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'Cookie authentication is only available to the first-party web apps',
            );
        }

        $user = $this->register->handle($request->fullName(), $request->phone(), $request->password());

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $user->load(['globalRoles', 'storeMemberships.role']);

        return (new CurrentUserResource($user))->response()->setStatusCode(201);
    }
}
