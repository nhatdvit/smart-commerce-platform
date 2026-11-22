<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the designed `UpdateCustomerProfileRequest`, narrowed to `name`:
 * see `UpdateCustomerName` for why phone and email are not accepted here yet.
 */
final class UpdateProfileRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập họ và tên.',
        ];
    }

    public function name(): string
    {
        return (string) $this->validated()['name'];
    }
}
