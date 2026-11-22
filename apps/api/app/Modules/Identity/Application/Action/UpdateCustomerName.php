<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Action;

use App\Modules\Identity\Infrastructure\Persistence\Model\User;

/**
 * The only profile field a customer may edit themselves in this slice.
 *
 * Phone is the login identity, so changing it is deliberately a separate,
 * not-yet-built concern with its own verification - never a side effect of
 * this screen (CLAUDE.md section 15 leaves guest/customer identity policy
 * open; this does not widen it on its own).
 */
final class UpdateCustomerName
{
    public function handle(User $user, string $name): User
    {
        $user->full_name = $name;
        $user->save();

        return $user;
    }
}
