<?php

declare(strict_types=1);

namespace App\Modules\Order\Infrastructure\Persistence;

use App\Modules\Order\Domain\Contract\CustomerDirectory;
use App\Modules\Order\Domain\Criteria\CustomerCriteria;
use App\Modules\Order\Domain\Entity\CustomerSummary;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent implementation of the guest-customer directory.
 *
 * Built directly over `orders` (and a read-only join into `payments`, the
 * direction Order already depends in - see `ProvidesPaymentSummaries`), never
 * a dedicated `customers` table: there is no registered-account module yet,
 * so grouping orders by contact phone is the whole of what "a customer" means
 * here (CLAUDE.md section 5 still lists `Customer` as a future module).
 *
 * @phpstan-type CustomerRow object{phone: string, name: string, email: string|null, order_count: int|string, total_spent: int|string, last_order_at: string|null}
 */
final class EloquentCustomerDirectory implements CustomerDirectory
{
    /** Hard ceiling on a page, whatever the client asks for (CLAUDE.md section 8). */
    public const int MAX_LIMIT = 100;

    public function paginate(string $storeId, CustomerCriteria $criteria): array
    {
        $limit = max(1, min($criteria->limit, self::MAX_LIMIT));

        $query = $this->baseQuery($storeId);

        if ($criteria->search !== null && trim($criteria->search) !== '') {
            $needle = '%'.trim($criteria->search).'%';
            $query->where(function (Builder $scoped) use ($needle): void {
                $scoped->where('o.contact_phone', 'ilike', $needle)
                    ->orWhere('o.contact_name', 'ilike', $needle);
            });
        }

        $after = $this->decodeCursor($criteria->cursor);

        if ($after !== null) {
            // Keyset pagination on (last_order_at, phone): a plain row
            // comparison, which Postgres evaluates lexicographically the same
            // way the ORDER BY below reads - no page can shift under a client
            // while new orders arrive (CLAUDE.md section 8), the same
            // reasoning EloquentOrderRepository applies to a primary key.
            $query->havingRaw(
                '(max(o.created_at), o.contact_phone) < (?::timestamptz, ?::text)',
                [$after['lastOrderAt']->format(DateTimeImmutable::ATOM), $after['phone']],
            );
        }

        $rows = $query
            ->orderByDesc('last_order_at')
            ->orderByDesc('o.contact_phone')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $page = $hasMore ? $rows->take($limit) : $rows;

        /** @var CustomerRow|null $last */
        $last = $page->last();

        return [
            'items' => array_values($page->map(function (object $row): CustomerSummary {
                /** @var CustomerRow $row */
                return $this->toEntity($row);
            })->all()),
            'next_cursor' => $hasMore && $last !== null ? $this->encodeCursor($last) : null,
            'has_more' => $hasMore,
        ];
    }

    private function baseQuery(string $storeId): Builder
    {
        return DB::table('orders as o')
            ->leftJoin('payments as p', function ($join) use ($storeId): void {
                $join->on('p.order_id', '=', 'o.id')->where('p.store_id', '=', $storeId);
            })
            ->where('o.store_id', $storeId)
            ->groupBy('o.contact_phone')
            ->selectRaw('o.contact_phone as phone')
            // The name and email shown are the ones on this phone's most
            // recent order - what a cashier would actually recognise today,
            // not whatever happened to sort first.
            ->selectRaw('(array_agg(o.contact_name order by o.created_at desc))[1] as name')
            ->selectRaw('(array_agg(o.contact_email order by o.created_at desc))[1] as email')
            ->selectRaw('count(*) as order_count')
            // Only a PAID payment counts as money actually spent - order
            // status and payment status are separate state machines
            // (CLAUDE.md section 6), and a COMPLETED order with its payment
            // still PENDING is not revenue yet.
            ->selectRaw("coalesce(sum(case when p.status = 'PAID' then o.total_amount else 0 end), 0) as total_spent")
            ->selectRaw('max(o.created_at) as last_order_at');
    }

    /** @param  CustomerRow  $row */
    private function toEntity(object $row): CustomerSummary
    {
        return new CustomerSummary(
            phone: (string) $row->phone,
            name: (string) $row->name,
            email: $row->email === null ? null : (string) $row->email,
            orderCount: (int) $row->order_count,
            totalSpent: Money::vnd((int) $row->total_spent),
            lastOrderAt: $row->last_order_at === null ? null : new DateTimeImmutable((string) $row->last_order_at),
        );
    }

    /**
     * Opaque to the client: a cursor is a position, not a value to construct.
     *
     * @param  CustomerRow  $row
     */
    private function encodeCursor(object $row): string
    {
        $lastOrderAt = new DateTimeImmutable((string) $row->last_order_at);

        return rtrim(strtr(
            base64_encode($lastOrderAt->format(DateTimeImmutable::ATOM).'|'.$row->phone),
            '+/',
            '-_',
        ), '=');
    }

    /** @return array{lastOrderAt: DateTimeImmutable, phone: string}|null */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if (! is_string($decoded)) {
            return null;
        }

        // A malformed cursor reads as "no cursor" rather than an error: it is
        // either a client bug or a stale link, and neither deserves a 500
        // (the same convention EloquentOrderRepository::decodeCursor follows).
        [$timestamp, $phone] = array_pad(explode('|', $decoded, 2), 2, null);

        if (! is_string($timestamp) || ! is_string($phone) || $phone === '') {
            return null;
        }

        $lastOrderAt = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $timestamp);

        return $lastOrderAt === false ? null : ['lastOrderAt' => $lastOrderAt, 'phone' => $phone];
    }
}
