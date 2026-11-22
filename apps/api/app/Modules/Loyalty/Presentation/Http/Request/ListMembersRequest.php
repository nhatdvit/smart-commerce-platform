<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Http\Request;

use App\Modules\Loyalty\Domain\Criteria\MemberCriteria;
use Illuminate\Foundation\Http\FormRequest;

/** Query parameters for the member list. */
final class ListMembersRequest extends FormRequest
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

    public function toCriteria(): MemberCriteria
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return new MemberCriteria(
            search: $this->trimmedOrNull($data['q'] ?? null),
            cursor: $this->trimmedOrNull($data['cursor'] ?? null),
            limit: $this->limit(),
        );
    }

    private function limit(): int
    {
        $value = $this->validated()['limit'] ?? null;

        return is_numeric($value) ? (int) $value : MemberCriteria::DEFAULT_LIMIT;
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
