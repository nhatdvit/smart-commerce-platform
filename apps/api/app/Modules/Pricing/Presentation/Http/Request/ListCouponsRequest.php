<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Presentation\Http\Request;

use App\Modules\Pricing\Domain\Criteria\CouponCriteria;
use App\Modules\Pricing\Domain\Enum\CouponStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Query parameters for the admin coupon list. */
final class ListCouponsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'nullable', 'string', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'array', 'max:3'],
            'status.*' => ['string', Rule::in(array_column(CouponStatus::cases(), 'value'))],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    public function toCriteria(): CouponCriteria
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return new CouponCriteria(
            statuses: $this->statuses(),
            search: $this->trimmedOrNull($data['q'] ?? null),
            cursor: $this->trimmedOrNull($data['cursor'] ?? null),
            limit: $this->limit(),
        );
    }

    /**
     * Statuses to include; empty means every status.
     *
     * @return list<CouponStatus>
     */
    private function statuses(): array
    {
        /** @var array<int, string> $values */
        $values = (array) ($this->validated()['status'] ?? []);

        return array_values(array_map(
            static fn (string $value): CouponStatus => CouponStatus::from($value),
            $values,
        ));
    }

    private function limit(): int
    {
        $value = $this->validated()['limit'] ?? null;

        // A query parameter arrives as a string however numeric the rule was.
        return is_numeric($value) ? (int) $value : CouponCriteria::DEFAULT_LIMIT;
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
