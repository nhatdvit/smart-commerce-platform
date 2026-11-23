<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Http\Request;

use App\Modules\Order\Domain\Criteria\OrderCriteria;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query parameters for a signed-in customer's own order history.
 *
 * Deliberately smaller than {@see ListOrdersRequest}: a customer reading
 * their own orders has no reason to filter by status, fulfillment type or
 * a search term - every row is already theirs, and there are at most a few
 * dozen. Only paging is exposed.
 */
final class ListMyOrdersRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'nullable', 'string', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function toCriteria(): OrderCriteria
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        $limit = $data['limit'] ?? null;
        $cursor = $data['cursor'] ?? null;

        return new OrderCriteria(
            // Most recent first: what a customer wants from their own
            // history, unlike the operations queue this criteria also
            // serves (see OrderCriteria's own doc comment).
            oldestFirst: false,
            cursor: is_string($cursor) && trim($cursor) !== '' ? trim($cursor) : null,
            limit: is_numeric($limit) ? (int) $limit : 20,
        );
    }
}
