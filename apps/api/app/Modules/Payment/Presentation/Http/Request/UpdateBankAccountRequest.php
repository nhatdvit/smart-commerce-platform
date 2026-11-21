<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Request;

use App\Modules\Payment\Application\DTO\BankAccountInput;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateBankAccountRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bank_bin' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            'account_number' => ['required', 'string', 'regex:/^[0-9]{6,20}$/'],
            'account_name' => ['required', 'string', 'max:160'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'bank_bin.regex' => 'Mã ngân hàng phải gồm đúng 6 chữ số.',
            'account_number.regex' => 'Số tài khoản phải gồm 6 đến 20 chữ số.',
        ];
    }

    public function toInput(): BankAccountInput
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return new BankAccountInput(
            bankBin: (string) $data['bank_bin'],
            accountNumber: (string) $data['account_number'],
            accountName: (string) $data['account_name'],
            isDefault: (bool) ($data['is_default'] ?? true),
        );
    }
}
