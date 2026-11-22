<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the designed `RegisterRequest`: customer self-registration by
 * phone number, the identity Loyalty already keys its ledger on (CLAUDE.md
 * section 6). Staff accounts are provisioned by an admin, not self-service,
 * so there is no email variant of this endpoint.
 */
final class RegisterRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:2', 'max:150'],
            'phone' => [
                'required',
                'string',
                'max:20',
                // A Vietnamese mobile number: 10 digits, or +84 in place of
                // the leading 0.
                'regex:/^(0|\+84)(3|5|7|8|9)[0-9]{8}$/',
                Rule::unique('users', 'phone'),
            ],
            // Same floor as LoginRequest's password, for the same reason: a
            // rule for choosing one, not for typing one.
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'full_name.required' => 'Vui lòng nhập họ và tên.',
            'phone.required' => 'Vui lòng nhập số điện thoại.',
            'phone.regex' => 'Số điện thoại không hợp lệ.',
            'phone.unique' => 'Số điện thoại này đã được đăng ký.',
            'password.required' => 'Vui lòng nhập mật khẩu.',
            'password.min' => 'Mật khẩu phải có ít nhất 8 ký tự.',
        ];
    }

    public function fullName(): string
    {
        return (string) $this->validated()['full_name'];
    }

    public function phone(): string
    {
        return (string) $this->validated()['phone'];
    }

    public function password(): string
    {
        return (string) $this->validated()['password'];
    }
}
