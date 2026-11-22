<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Http\Request;

use App\Modules\Order\Domain\Criteria\CustomerCriteria;
use Illuminate\Foundation\Http\FormRequest;

/** Query parameters for the guest-customer directory. */
final class ListCustomersRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'nullable', 'string', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    public function toCriteria(): CustomerCriteria
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return new CustomerCriteria(
            search: $this->trimmedOrNull($data['q'] ?? null),
            cursor: $this->trimmedOrNull($data['cursor'] ?? null),
            limit: $this->limit(),
        );
    }

    private function limit(): int
    {
        $value = $this->validated()['limit'] ?? null;

        return is_numeric($value) ? (int) $value : CustomerCriteria::DEFAULT_LIMIT;
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
