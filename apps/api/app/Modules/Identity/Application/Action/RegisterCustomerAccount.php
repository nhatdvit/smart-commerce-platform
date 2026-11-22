<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Action;

use App\Modules\Identity\Infrastructure\Persistence\Model\User;
use Illuminate\Support\Facades\Hash;

/**
 * Self-service registration: a customer, not a staff member, so the new
 * account gets no role and no store membership - `store.can` denies it
 * everywhere by simply having nothing to grant (CLAUDE.md section 8).
 *
 * Phone uniqueness is enforced by `RegisterRequest`'s validation rule, which
 * runs before this action - a second race arriving between the check and the
 * insert still fails, on the column's own unique constraint.
 */
final class RegisterCustomerAccount
{
    public function handle(string $fullName, string $phone, string $password): User
    {
        return User::forceCreate([
            'full_name' => $fullName,
            'phone' => $phone,
            'password_hash' => Hash::make($password),
        ]);
    }
}
