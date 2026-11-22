<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Action;

use App\Modules\Identity\Infrastructure\Persistence\Model\User;
use Illuminate\Support\Facades\Hash;

/**
 * Self-service password change. Requires the current password rather than
 * just the session: a session can be left open on a shared device, and a
 * password change is the one action that must still prove the account is
 * the one acting.
 */
final class ChangeCustomerPassword
{
    /** False, and nothing written, when the current password does not match. */
    public function handle(User $user, string $currentPassword, string $newPassword): bool
    {
        if (! Hash::check($currentPassword, (string) $user->password_hash)) {
            return false;
        }

        $user->forceFill(['password_hash' => Hash::make($newPassword)])->save();

        return true;
    }
}
