<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Http\Request;

use App\Modules\Order\Domain\Criteria\OrderCriteria;
use App\Modules\Order\Domain\Enum\FulfillmentType;
use App\Modules\Order\Domain\Enum\OrderStatus;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query parameters for the operations queue.
 *
 * `payment_status` is designed but NOT supported here yet, and is refused
 * rather than ignored. Silently dropping it would show a cashier "unpaid cash
 * orders" that are in fact every order in the shop - a filter that lies is
 * worse than a filter that is missing. It arrives with the payments screen,
 * which is where reading payment state belongs.
 */
final class ListOrdersRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'nullable', 'string', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],

            'status' => ['sometimes', 'array', 'max:8'],
            'status.*' => ['string', Rule::in(array_column(OrderStatus::cases(), 'value'))],

            'fulfillment_type' => [
                'sometimes',
                'string',
                Rule::in(array_column(FulfillmentType::cases(), 'value')),
            ],

            'placed_from' => ['sometimes', 'nullable', 'date'],
            'placed_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:placed_from'],

            'order_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],

            'sort' => ['sometimes', 'string', Rule::in(['oldest_first', 'newest_first'])],

            'payment_status' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'payment_status.prohibited' => 'Lọc theo trạng thái thanh toán sẽ có cùng màn hình Thanh toán.',
            'placed_to.after_or_equal' => 'Mốc kết thúc phải sau hoặc bằng mốc bắt đầu.',
        ];
    }

    public function toCriteria(): OrderCriteria
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return new OrderCriteria(
            statuses: $this->statuses(),
            orderCode: $this->trimmedOrNull($data['order_code'] ?? null),
            search: $this->trimmedOrNull($data['q'] ?? null),
            fulfillmentType: isset($data['fulfillment_type']) && is_string($data['fulfillment_type'])
                ? FulfillmentType::from($data['fulfillment_type'])
                : null,
            placedFrom: $this->timestamp($data['placed_from'] ?? null),
            placedTo: $this->timestamp($data['placed_to'] ?? null),
            // The work queue's default: the oldest unserved order is the most
            // urgent one. A client that wants a history list asks for the other.
            oldestFirst: ($data['sort'] ?? 'oldest_first') !== 'newest_first',
            cursor: $this->trimmedOrNull($data['cursor'] ?? null),
            limit: $this->limit(),
        );
    }

    /**
     * Statuses to include; empty means every status.
     *
     * @return list<OrderStatus>
     */
    private function statuses(): array
    {
        /** @var array<int, string> $values */
        $values = (array) ($this->validated()['status'] ?? []);

        return array_values(array_map(
            static fn (string $value): OrderStatus => OrderStatus::from($value),
            $values,
        ));
    }

    private function limit(): int
    {
        $value = $this->validated()['limit'] ?? null;

        // A query parameter arrives as a string however numeric the rule was,
        // so this casts rather than type-checks: reading is_int here would
        // silently ignore every ?limit= a client ever sent.
        return is_numeric($value) ? (int) $value : OrderCriteria::DEFAULT_LIMIT;
    }

    /**
     * Parses a bound into UTC, which is what the column holds.
     *
     * A value without a zone is read as UTC rather than store-local on
     * purpose: these two parameters are documented as UTC instants. Store-local
     * day boundaries are a different question, and the day the reporting
     * endpoints need it they will say so explicitly.
     */
    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $value)
            ?: new DateTimeImmutable($value, new DateTimeZone('UTC'));

        return $parsed->setTimezone(new DateTimeZone('UTC'));
    }

    private function trimmedOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
