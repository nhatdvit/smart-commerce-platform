<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/** Validates the designed `ChangePasswordRequest`. */
final class ChangePasswordRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:255'],
            // Same floor as RegisterRequest's password, for the same reason.
            'new_password' => ['required', 'string', 'min:8', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Vui lòng nhập mật khẩu hiện tại.',
            'new_password.required' => 'Vui lòng nhập mật khẩu mới.',
            'new_password.min' => 'Mật khẩu mới phải có ít nhất 8 ký tự.',
        ];
    }

    public function currentPassword(): string
    {
        return (string) $this->validated()['current_password'];
    }

    public function newPassword(): string
    {
        return (string) $this->validated()['new_password'];
    }
}
