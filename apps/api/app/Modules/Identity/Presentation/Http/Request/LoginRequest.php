<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the designed `LoginRequest`.
 *
 * Staff sign in by email; a customer who registered by phone signs in by
 * phone - the same `users` row, the same session guard, two ways to name the
 * account. Exactly one of the two must be present.
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
            'email' => ['required_without:phone', 'nullable', 'string', 'email', 'max:254'],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:20'],
            // Minimum length is a rule for CHOOSING a password, not for typing
            // one: enforcing it here would tell an attacker which guesses are
            // too short to be real.
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array{phone: string, password: string}|array{email: string, password: string} */
    public function credentials(): array
    {
        $phone = $this->validated()['phone'] ?? null;

        if ($phone !== null && $phone !== '') {
            return ['phone' => (string) $phone, 'password' => (string) $this->validated()['password']];
        }

        return ['email' => (string) $this->validated()['email'], 'password' => (string) $this->validated()['password']];
    }

    /** Which field this attempt used to name the account - for the one invalid-credentials message. */
    public function identityField(): string
    {
        $phone = $this->validated()['phone'] ?? null;

        return $phone !== null && $phone !== '' ? 'phone' : 'email';
    }

    public function remember(): bool
    {
        return (bool) ($this->validated()['remember'] ?? false);
    }
}
