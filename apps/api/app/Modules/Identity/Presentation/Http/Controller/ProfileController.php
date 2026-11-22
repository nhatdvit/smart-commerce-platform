<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Controller;

use App\Modules\Identity\Application\Action\ChangeCustomerPassword;
use App\Modules\Identity\Application\Action\UpdateCustomerName;
use App\Modules\Identity\Infrastructure\Persistence\Model\User;
use App\Modules\Identity\Presentation\Http\Request\ChangePasswordRequest;
use App\Modules\Identity\Presentation\Http\Request\UpdateProfileRequest;
use App\Modules\Identity\Presentation\Http\Resource\CustomerProfileResource;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\Controllers\Controller;
use App\Shared\Presentation\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A signed-in customer's own profile and password - see `UpdateCustomerName`
 * for why neither endpoint here touches phone or email.
 */
final class ProfileController extends Controller
{
    public function __construct(
        private readonly UpdateCustomerName $updateName,
        private readonly ChangeCustomerPassword $changePassword,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return (new CustomerProfileResource($this->user($request)))->response();
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $updated = $this->updateName->handle($this->user($request), $request->name());

        return (new CustomerProfileResource($updated))->response();
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $changed = $this->changePassword->handle(
            $this->user($request),
            $request->currentPassword(),
            $request->newPassword(),
        );

        if (! $changed) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'The current password is incorrect',
                [['field' => 'current_password', 'message' => 'Mật khẩu hiện tại không đúng.']],
            );
        }

        return new JsonResponse(status: 204);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('Authenticated principal is not a User');
        }

        return $user;
    }
}
