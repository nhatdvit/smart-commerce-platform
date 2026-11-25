<?php

declare(strict_types=1);

namespace App\Modules\Order\Infrastructure\Persistence;

use App\Modules\Identity\Application\StaffDirectory;
use App\Modules\Order\Domain\Contract\OrderRepository;
use App\Modules\Order\Domain\Criteria\OrderCriteria;
use App\Modules\Order\Domain\Entity\Order;
use App\Modules\Order\Domain\Entity\OrderItem;
use App\Modules\Order\Domain\Entity\OrderStatusChange;
use App\Modules\Order\Domain\Enum\OrderStatus;
use App\Modules\Order\Domain\ValueObject\OrderContact;
use App\Modules\Order\Domain\ValueObject\OrderTotals;
use App\Modules\Order\Infrastructure\Persistence\Model\Order as OrderModel;
use App\Modules\Order\Infrastructure\Persistence\Model\OrderItem as OrderItemModel;
use App\Modules\Order\Infrastructure\Persistence\Model\OrderStatusChange as OrderStatusChangeModel;
use App\Shared\Domain\ValueObject\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent implementation of the order seam.
 *
 * The only class in the module that knows the order tables exist, and where
 * rows become domain objects (CLAUDE.md section 16). Every read is
 * store-scoped by construction: there is no method here that can see across
 * stores.
 *
 * Nothing deletes. An order is cancelled or it expires; the row stays, and so
 * does every line and every recorded transition (section 7).
 */
final class EloquentOrderRepository implements OrderRepository
{
    /** Hard ceiling on a page, whatever the client asks for (CLAUDE.md section 8). */
    public const int MAX_LIMIT = 100;

    /**
     * Staff names come from Identity's exported directory, never from a join
     * into its tables (CLAUDE.md section 3).
     */
    public function __construct(private readonly StaffDirectory $staff) {}

    public function findInStore(string $storeId, string $orderId): ?Order
    {
        $row = $this->baseQuery($storeId)
            ->with(['statusChanges' => static fn ($query) => $query->orderBy('created_at')->orderBy('id')])
            ->whereKey($orderId)
            ->first();

        return $row === null ? null : $this->toEntity($row);
    }

    public function paginate(string $storeId, OrderCriteria $criteria): array
    {
        return $this->paginateQuery($storeId, $criteria, null);
    }

    public function paginateForCustomer(string $storeId, string $customerId, OrderCriteria $criteria): array
    {
        return $this->paginateQuery($storeId, $criteria, $customerId);
    }

    public function findForUpdate(string $storeId, string $orderId): ?Order
    {
        $row = $this->baseQuery($storeId)
            ->whereKey($orderId)
            // The row, not the table: PostgreSQL locks exactly this order for
            // the rest of the caller transaction, so a second staff member
            // advancing it waits and then sees the new status.
            ->lockForUpdate()
            ->first();

        return $row === null ? null : $this->toEntity($row);
    }

    public function recordTransition(
        Order $order,
        OrderStatus $from,
        ?string $actorId,
        ?string $reason,
    ): Order {
        $row = OrderModel::query()
            ->where('store_id', $order->storeId)
            ->whereKey($order->id)
            ->sole();

        $row->fill([
            'status' => $order->status->value,
            // Written here because entering the service flow is what sets it,
            // and the entity decided that; see Order::transitionTo.
            'placed_at' => $order->placedAt,
        ])->save();

        OrderStatusChangeModel::query()->create([
            'store_id' => $order->storeId,
            'order_id' => $order->id,
            'from_status' => $from->value,
            'to_status' => $order->status->value,
            'actor_id' => $actorId,
            'reason' => $reason,
            'created_at' => now()->toImmutable(),
        ]);

        $reloaded = $this->findInStore($order->storeId, (string) $order->id);

        // The row was read with sole() above, so it exists; this keeps the
        // return type honest without a nullable leaking to the caller.
        return $reloaded ?? $order;
    }

    public function openStatusCounts(string $storeId): array
    {
        $open = array_values(array_map(
            static fn (OrderStatus $status): string => $status->value,
            array_filter(
                OrderStatus::cases(),
                static fn (OrderStatus $status): bool => $status->isOpen(),
            ),
        ));

        // Every open status present with a zero, so a client can render the
        // whole set of counters without inventing missing keys.
        $counts = array_fill_keys($open, 0);

        // The query builder rather than Eloquent: this is an aggregate, and
        // hydrating models to count them would be the slower, stranger way to
        // ask (CLAUDE.md section 7 allows exactly this).
        $rows = DB::table('orders')
            ->selectRaw('status, count(*) as total')
            ->where('store_id', $storeId)
            ->whereIn('status', $open)
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $status = $row->status;
            $total = $row->total;

            if (is_string($status) && array_key_exists($status, $counts) && is_numeric($total)) {
                $counts[$status] = (int) $total;
            }
        }

        return $counts;
    }

    public function pendingPaymentOrderIds(string $storeId): array
    {
        return array_values(
            DB::table('orders')
                ->where('store_id', $storeId)
                ->where('status', OrderStatus::PendingPayment->value)
                ->pluck('id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all(),
        );
    }

    /**
     * @return array{items: list<Order>, next_cursor: string|null, has_more: bool}
     */
    private function paginateQuery(string $storeId, OrderCriteria $criteria, ?string $customerId): array
    {
        $limit = max(1, min($criteria->limit, self::MAX_LIMIT));

        $query = $this->baseQuery($storeId);

        if ($customerId !== null) {
            $query->where('customer_id', $customerId);
        }

        if ($criteria->statuses !== []) {
            $query->whereIn('status', array_map(
                static fn (OrderStatus $status): string => $status->value,
                $criteria->statuses,
            ));
        }

        if ($criteria->fulfillmentType !== null) {
            $query->where('fulfillment_type', $criteria->fulfillmentType->value);
        }

        // Exact, because this is the code a customer read out over the phone -
        // a partial match would hand staff somebody else's order.
        if ($criteria->orderCode !== null && trim($criteria->orderCode) !== '') {
            $query->where('order_code', trim($criteria->orderCode));
        }

        // Inclusive lower bound, exclusive upper, as the contract documents.
        // An order still waiting for a transfer has no placed_at and is
        // therefore outside any placed-at window, which is the honest answer.
        if ($criteria->placedFrom !== null) {
            $query->where('placed_at', '>=', $criteria->placedFrom);
        }

        if ($criteria->placedTo !== null) {
            $query->where('placed_at', '<', $criteria->placedTo);
        }

        if ($criteria->search !== null && trim($criteria->search) !== '') {
            $this->applySearch($query, trim($criteria->search));
        }

        // Cursor pagination over the primary key, not an offset: UUIDv7 keys
        // are time-ordered, so the key order IS the chronological order and a
        // page cannot shift under a client while new orders arrive
        // (CLAUDE.md section 8).
        $after = $this->decodeCursor($criteria->cursor);

        if ($after !== null) {
            $query->where('id', $criteria->oldestFirst ? '>' : '<', $after);
        }

        $rows = $query
            ->orderBy('id', $criteria->oldestFirst ? 'asc' : 'desc')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $page = $hasMore ? $rows->take($limit) : $rows;
        $last = $page->last();

        return [
            'items' => array_values($page->map(fn (OrderModel $row): Order => $this->toEntity($row))->all()),
            'next_cursor' => $hasMore && $last instanceof OrderModel ? $this->encodeCursor($last->id) : null,
            'has_more' => $hasMore,
        ];
    }

    /**
     * Orders with everything a response needs, store-scoped.
     *
     * The lines are eager-loaded because strict mode turns a lazy load into an
     * exception - which is the point: an N+1 on the busiest read in the
     * application should fail in development, not in morning traffic.
     *
     * @return Builder<OrderModel>
     */
    private function baseQuery(string $storeId): Builder
    {
        return OrderModel::query()
            ->where('store_id', $storeId)
            ->with(['items' => static fn ($query) => $query->orderBy('sort_order')->orderBy('id')]);
    }

    /**
     * What staff actually type: a code read aloud, a customer name, or a dish.
     *
     * `ilike` rather than `lower(...) = lower(...)`: the Alpine PostgreSQL
     * image runs a C-collation cluster where `lower()` leaves Vietnamese
     * letters alone, so case folding has to come from the operator, not from
     * the function (see Shared\Domain\ValueObject\DisplayName).
     *
     * @param  Builder<OrderModel>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        $needle = '%'.$search.'%';

        $query->where(function (Builder $scoped) use ($needle): void {
            $scoped->where('order_code', 'ilike', $needle)
                ->orWhere('contact_name', 'ilike', $needle)
                ->orWhere('contact_phone', 'ilike', $needle)
                // The dish, which is how staff look up "who ordered the xôi".
                // An EXISTS rather than a join, so one order with three
                // matching lines is still one row.
                ->orWhereExists(static function ($lines) use ($needle): void {
                    $lines->select(DB::raw(1))
                        ->from('order_items')
                        ->whereColumn('order_items.order_id', 'orders.id')
                        ->where('order_items.product_name', 'ilike', $needle);
                });
        });
    }

    private function toEntity(OrderModel $row): Order
    {
        return Order::create(
            $row->id,
            $row->store_id,
            $row->order_code,
            $row->status,
            $row->fulfillment_type,
            $row->customer_id,
            OrderContact::create($row->contact_name, $row->contact_phone, $row->contact_email),
            $row->delivery_address_line,
            $row->note,
            $row->scheduled_at,
            array_values($row->items->map(static fn (OrderItemModel $item): OrderItem => OrderItem::stored(
                $item->id,
                $item->product_id,
                $item->product_variant_id,
                $item->product_name,
                $item->variant_name,
                $item->quantity,
                Money::vnd($item->unit_price_amount),
                Money::vnd($item->modifiers_total_amount),
                Money::vnd($item->line_total_amount),
                $item->note,
                $item->sort_order,
            ))->all()),
            // The stored totals, trusted: a receipt records what was agreed
            // and is not a formula to re-evaluate under today rules.
            OrderTotals::stored(
                Money::vnd($row->subtotal_amount),
                Money::vnd($row->discount_amount),
                Money::vnd($row->delivery_fee_amount),
                Money::vnd($row->tax_amount),
                Money::vnd($row->total_amount),
            ),
            $row->placed_at,
            $row->created_at,
            $this->historyOf($row),
        );
    }

    /**
     * The recorded transitions, when they were loaded.
     *
     * The list endpoint does not read them, so it does not pay for them; the
     * relation is only present on a detail read.
     *
     * @return list<OrderStatusChange>
     */
    private function historyOf(OrderModel $row): array
    {
        if (! $row->relationLoaded('statusChanges')) {
            return [];
        }

        $actorIds = array_values(array_filter(
            $row->statusChanges->map(static fn (OrderStatusChangeModel $change): ?string => $change->actor_id)->all(),
            static fn (?string $id): bool => $id !== null,
        ));

        $names = $this->staff->namesByIds($actorIds);

        return array_values($row->statusChanges
            ->map(static fn (OrderStatusChangeModel $change): OrderStatusChange => new OrderStatusChange(
                $change->from_status,
                $change->to_status,
                $change->actor_id,
                $change->actor_id === null ? null : ($names[$change->actor_id] ?? null),
                $change->reason,
                $change->created_at,
            ))
            ->all());
    }

    /** Opaque to the client: a cursor is a position, not a value to construct. */
    private function encodeCursor(string $id): string
    {
        return rtrim(strtr(base64_encode($id), '+/', '-_'), '=');
    }

    private function decodeCursor(?string $cursor): ?string
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        // A malformed cursor reads as "no cursor" rather than an error: it is
        // either a client bug or a stale link, and neither deserves a 500.
        return is_string($decoded) && preg_match('/^[0-9a-f-]{36}$/i', $decoded) === 1
            ? $decoded
            : null;
    }
}
