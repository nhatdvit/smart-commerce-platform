<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Request;

use App\Modules\Payment\Domain\Criteria\PaymentCriteria;
use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Modules\Payment\Domain\Enum\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Query parameters for the reconciliation queue. */
final class ListAdminPaymentsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'nullable', 'string', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'array', 'max:6'],
            'status.*' => ['string', Rule::in(array_column(PaymentStatus::cases(), 'value'))],
            'method' => ['sometimes', 'nullable', 'string', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            // Laravel's own `boolean` rule only accepts 0/1/"0"/"1" - not the
            // "true"/"false" strings a query string naturally carries (every
            // browser `URLSearchParams` call produces exactly that). Accepting
            // both is what makes the documented `type: boolean` query
            // parameter actually usable from a query string.
            'needs_attention' => ['sometimes', 'nullable', Rule::in(['0', '1', 'true', 'false', 0, 1, true, false])],
            'transfer_reference' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    public function toCriteria(): PaymentCriteria
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return new PaymentCriteria(
            statuses: $this->statuses(),
            method: isset($data['method']) && is_string($data['method'])
                ? PaymentMethod::from($data['method'])
                : null,
            needsAttention: isset($data['needs_attention']) ? $this->toBool($data['needs_attention']) : null,
            transferReference: $this->trimmedOrNull($data['transfer_reference'] ?? null),
            cursor: $this->trimmedOrNull($data['cursor'] ?? null),
            limit: $this->limit(),
        );
    }

    /** @return list<PaymentStatus> */
    private function statuses(): array
    {
        /** @var array<int, string> $values */
        $values = (array) ($this->validated()['status'] ?? []);

        return array_values(array_map(
            static fn (string $value): PaymentStatus => PaymentStatus::from($value),
            $values,
        ));
    }

    private function limit(): int
    {
        $value = $this->validated()['limit'] ?? null;

        return is_numeric($value) ? (int) $value : PaymentCriteria::DEFAULT_LIMIT;
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * `(bool) "false"` is `true` - PHP casts any non-empty string as truthy -
     * so the accepted values above are matched explicitly instead.
     */
    private function toBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
