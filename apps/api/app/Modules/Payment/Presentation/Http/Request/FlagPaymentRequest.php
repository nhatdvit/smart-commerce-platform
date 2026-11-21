<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Request;

use App\Modules\Payment\Application\DTO\FlagPaymentInput;
use App\Modules\Payment\Domain\Enum\DiscrepancyType;
use App\Shared\Domain\ValueObject\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class FlagPaymentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_column(DiscrepancyType::cases(), 'value'))],
            'observed_amount' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]+$/'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'observed_amount.regex' => 'Số tiền phải là số nguyên đồng, không âm.',
        ];
    }

    public function toInput(): FlagPaymentInput
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        $observedAmount = $data['observed_amount'] ?? null;

        return new FlagPaymentInput(
            type: DiscrepancyType::from((string) $data['type']),
            observedAmount: is_string($observedAmount) ? Money::fromString($observedAmount) : null,
            note: isset($data['note']) && is_string($data['note']) ? trim($data['note']) : null,
        );
    }
}
