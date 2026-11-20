<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the designed `LoginRequest`.
 *
 * The password is never logged or echoed: bootstrap/app.php already lists it
 * among the fields excluded from flashed input.
 */
final class LoginRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:254'],
            // Minimum length is a rule for CHOOSING a password, not for typing
            // one: enforcing it here would tell an attacker which guesses are
            // too short to be real.
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function email(): string
    {
        return (string) $this->validated()['email'];
    }

    public function password(): string
    {
        return (string) $this->validated()['password'];
    }

    public function remember(): bool
    {
        return (bool) ($this->validated()['remember'] ?? false);
    }
}
