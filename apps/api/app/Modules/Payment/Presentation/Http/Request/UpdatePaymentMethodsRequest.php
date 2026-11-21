<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Request;

use App\Modules\Payment\Domain\Enum\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePaymentMethodsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'methods' => ['required', 'array', 'min:1', 'max:2'],
            'methods.*.method' => [
                'required',
                'string',
                'distinct',
                Rule::in(array_column(PaymentMethod::cases(), 'value')),
            ],
            'methods.*.enabled' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'methods.*.method.distinct' => 'Mỗi phương thức chỉ được gửi một lần.',
        ];
    }

    /**
     * Every MVP method, defaulting an omitted one to its CURRENT setting
     * rather than to disabled - a request that only mentions one method is
     * meant to change that one, not silently turn the other off.
     *
     * @param  array<string, bool>  $current
     * @return array<string, bool>
     */
    public function toEnabledMap(array $current): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = (array) ($this->validated()['methods'] ?? []);
        $result = $current;

        foreach ($rows as $row) {
            $result[(string) $row['method']] = (bool) $row['enabled'];
        }

        return $result;
    }
}
