<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Request;

use App\Modules\Catalog\Domain\Enum\CatalogStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Query parameters for the admin product list. */
final class ListProductsRequest extends FormRequest
{
    public const int DEFAULT_LIMIT = 50;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'nullable', 'string', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'array', 'max:3'],
            'status.*' => ['string', Rule::in(array_column(CatalogStatus::cases(), 'value'))],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    /**
     * Statuses to include; empty means every status.
     *
     * @return list<CatalogStatus>
     */
    public function statuses(): array
    {
        /** @var array<int, string> $values */
        $values = (array) ($this->validated()['status'] ?? []);

        return array_values(array_map(
            static fn (string $value): CatalogStatus => CatalogStatus::from($value),
            $values,
        ));
    }

    public function search(): ?string
    {
        $value = $this->validated()['q'] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function cursor(): ?string
    {
        $value = $this->validated()['cursor'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function limit(): int
    {
        $value = $this->validated()['limit'] ?? null;

        // A query parameter arrives as a string however numeric the validation
        // rule was, so this casts rather than type-checks: reading `is_int`
        // here would silently ignore every ?limit= a client ever sent.
        return is_numeric($value) ? (int) $value : self::DEFAULT_LIMIT;
    }
}
