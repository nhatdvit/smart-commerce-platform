<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Infrastructure\Persistence;

use App\Modules\Identity\Application\StaffDirectory;
use App\Modules\Loyalty\Domain\Contract\LoyaltyLedger;
use App\Modules\Loyalty\Domain\Criteria\MemberCriteria;
use App\Modules\Loyalty\Domain\Entity\LedgerEntry;
use App\Modules\Loyalty\Domain\Entity\LoyaltyMember;
use App\Modules\Loyalty\Domain\Enum\EntryType;
use App\Modules\Loyalty\Domain\Enum\MembershipTier;
use App\Modules\Loyalty\Domain\ValueObject\LoyaltySettings;
use App\Modules\Loyalty\Infrastructure\Persistence\Model\LedgerEntryModel;
use App\Modules\Loyalty\Infrastructure\Persistence\Model\RewardModel;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent implementation of the points ledger.
 *
 * Reads `orders.contact_phone`/`contact_name` and `payments.status` directly
 * rather than through an exported contract - the same pragmatic choice
 * {@see \App\Modules\Order\Infrastructure\Persistence\EloquentCustomerDirectory}
 * already made for the same reason (see this module's migration note): both
 * Order and Payment carry open, unrelated, in-flight changes, and this reads
 * in the direction the application already allows. Replace with a proper
 * export once those settle.
 *
 * @phpstan-type MemberRow object{phone: string, name: string, lifetime_earned: int|string, balance: int|string}
 */
final class EloquentLoyaltyLedger implements LoyaltyLedger
{
    public const int MAX_LIMIT = 100;

    public function __construct(private readonly StaffDirectory $staff) {}

    public function paginateMembers(string $storeId, MemberCriteria $criteria): array
    {
        $limit = max(1, min($criteria->limit, self::MAX_LIMIT));

        $query = $this->membersQuery($storeId);

        if ($criteria->search !== null && trim($criteria->search) !== '') {
            $needle = '%'.trim($criteria->search).'%';
            $query->where(function (Builder $scoped) use ($needle): void {
                $scoped->where('l.phone', 'ilike', $needle)
                    ->orWhereExists(function ($orders) use ($needle): void {
                        $orders->selectRaw('1')
                            ->from('orders as o')
                            ->whereColumn('o.contact_phone', 'l.phone')
                            ->where('o.contact_name', 'ilike', $needle);
                    });
            });
        }

        $after = $this->decodeCursor($criteria->cursor);

        if ($after !== null) {
            $query->havingRaw(
                'sum(case when l.type = \'EARN\' then l.points else 0 end) < ? or (sum(case when l.type = \'EARN\' then l.points else 0 end) = ? and l.phone < ?)',
                [$after['lifetimeEarned'], $after['lifetimeEarned'], $after['phone']],
            );
        }

        $rows = $query
            ->orderByDesc('lifetime_earned')
            ->orderByDesc('l.phone')
            ->limit($limit + 1)
            ->get();

        $hasMore = $rows->count() > $limit;
        $page = $hasMore ? $rows->take($limit) : $rows;

        /** @var MemberRow|null $last */
        $last = $page->last();

        $settings = $this->settingsFor($storeId);

        return [
            'items' => array_values($page->map(function (object $row) use ($settings): LoyaltyMember {
                /** @var MemberRow $row */
                return $this->toMember($row, $settings);
            })->all()),
            'next_cursor' => $hasMore && $last !== null ? $this->encodeCursor($last) : null,
            'has_more' => $hasMore,
        ];
    }

    public function balanceOf(string $storeId, string $phone): array
    {
        $row = DB::table('loyalty_ledger_entries')
            ->where('store_id', $storeId)
            ->where('phone', $phone)
            ->selectRaw("coalesce(sum(case when type = 'EARN' then points else 0 end), 0) as lifetime_earned")
            ->selectRaw('coalesce(sum(points), 0) as balance')
            ->first();

        return [
            'lifetimeEarnedPoints' => $row === null ? 0 : (int) $row->lifetime_earned,
            'balance' => $row === null ? 0 : (int) $row->balance,
        ];
    }

    /** @return list<LedgerEntry> */
    public function historyFor(string $storeId, string $phone): array
    {
        $rows = LedgerEntryModel::query()
            ->where('store_id', $storeId)
            ->where('phone', $phone)
            ->with('reward')
            ->orderByDesc('created_at')
            ->get();

        $actorIds = array_values(array_filter(
            $rows->map(static fn (LedgerEntryModel $row): ?string => $row->actor_id)->all(),
            static fn (?string $id): bool => $id !== null,
        ));
        $names = $this->staff->namesByIds($actorIds);

        return array_values($rows->map(fn (LedgerEntryModel $row): LedgerEntry => new LedgerEntry(
            $row->id,
            $row->type,
            $row->points,
            $row->payment_id,
            $row->reward_id,
            $row->reward?->name,
            $row->note,
            $row->actor_id === null ? null : ($names[$row->actor_id] ?? null),
            $row->created_at,
        ))->all());
    }

    public function unsyncedPaidPayments(string $storeId): array
    {
        $rows = DB::table('payments as p')
            ->join('orders as o', function ($join) use ($storeId): void {
                $join->on('o.id', '=', 'p.order_id')->where('o.store_id', '=', $storeId);
            })
            ->where('p.store_id', $storeId)
            ->where('p.status', 'PAID')
            ->whereNotExists(function ($ledger): void {
                $ledger->selectRaw('1')
                    ->from('loyalty_ledger_entries as l')
                    ->whereColumn('l.payment_id', 'p.id');
            })
            ->select(['p.id as payment_id', 'o.contact_phone as phone', 'p.amount'])
            ->get();

        return array_values($rows->map(static fn (object $row): array => [
            'paymentId' => (string) $row->payment_id,
            'phone' => (string) $row->phone,
            'amount' => (int) $row->amount,
        ])->all());
    }

    public function recordEarn(string $storeId, string $phone, string $paymentId, int $points): void
    {
        try {
            LedgerEntryModel::query()->create([
                'store_id' => $storeId,
                'phone' => $phone,
                'type' => EntryType::Earn->value,
                'points' => $points,
                'payment_id' => $paymentId,
            ]);
        } catch (QueryException $e) {
            // 23505 is Postgres's unique_violation - this payment already has
            // an EARN row (the migration's partial unique index), which is
            // exactly the idempotency this method promises, not a fault.
            if ($e->getCode() !== '23505') {
                throw $e;
            }
        }
    }

    public function recordRedeem(
        string $storeId,
        string $phone,
        string $rewardId,
        int $points,
        string $actorId,
        ?string $note,
    ): LedgerEntry {
        $row = LedgerEntryModel::query()->create([
            'store_id' => $storeId,
            'phone' => $phone,
            'type' => EntryType::Redeem->value,
            'points' => -$points,
            'reward_id' => $rewardId,
            'actor_id' => $actorId,
            'note' => $note,
        ]);

        $reward = RewardModel::query()->find($rewardId);
        $names = $this->staff->namesByIds([$actorId]);

        return new LedgerEntry(
            $row->id,
            $row->type,
            $row->points,
            $row->payment_id,
            $row->reward_id,
            $reward?->name,
            $row->note,
            $names[$actorId] ?? null,
            $row->created_at,
        );
    }

    public function summary(string $storeId): array
    {
        $members = (int) (DB::table('loyalty_ledger_entries')
            ->where('store_id', $storeId)
            ->distinct()
            ->count('phone'));

        $pointsIssued = (int) (DB::table('loyalty_ledger_entries')
            ->where('store_id', $storeId)
            ->where('type', EntryType::Earn->value)
            ->sum('points'));

        $redemptionsThisMonth = (int) (DB::table('loyalty_ledger_entries')
            ->where('store_id', $storeId)
            ->where('type', EntryType::Redeem->value)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count());

        return [
            'members' => $members,
            'pointsIssued' => $pointsIssued,
            'redemptionsThisMonth' => $redemptionsThisMonth,
        ];
    }

    private function membersQuery(string $storeId): Builder
    {
        return DB::table('loyalty_ledger_entries as l')
            ->where('l.store_id', $storeId)
            ->groupBy('l.phone')
            ->selectRaw('l.phone as phone')
            // The name shown is this phone's most recent order contact name -
            // the same read EloquentCustomerDirectory does, duplicated here
            // because the two modules cannot yet depend on one shared export
            // (see the class note).
            ->selectRaw(
                '(select o.contact_name from orders o where o.contact_phone = l.phone '
                .'and o.store_id = ? order by o.created_at desc limit 1) as name',
                [$storeId],
            )
            ->selectRaw("sum(case when l.type = 'EARN' then l.points else 0 end) as lifetime_earned")
            ->selectRaw('sum(l.points) as balance');
    }

    private function settingsFor(string $storeId): LoyaltySettings
    {
        return (new EloquentLoyaltySettingsRepository)->forStore($storeId);
    }

    /** @param  MemberRow  $row */
    private function toMember(object $row, LoyaltySettings $settings): LoyaltyMember
    {
        $lifetimeEarned = (int) $row->lifetime_earned;

        return new LoyaltyMember(
            phone: (string) $row->phone,
            name: $row->name === null ? (string) $row->phone : (string) $row->name,
            lifetimeEarnedPoints: $lifetimeEarned,
            balance: (int) $row->balance,
            tier: MembershipTier::forLifetimeEarnedPoints($lifetimeEarned, $settings->loyalTierThresholdPoints),
        );
    }

    /** Opaque to the client: a cursor is a position, not a value to construct. */
    private function encodeCursor(object $row): string
    {
        /** @var MemberRow $row */
        return rtrim(strtr(
            base64_encode($row->lifetime_earned.'|'.$row->phone),
            '+/',
            '-_',
        ), '=');
    }

    /** @return array{lifetimeEarned: int, phone: string}|null */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if (! is_string($decoded)) {
            return null;
        }

        [$lifetimeEarned, $phone] = array_pad(explode('|', $decoded, 2), 2, null);

        if (! is_string($lifetimeEarned) || ! ctype_digit($lifetimeEarned) || ! is_string($phone) || $phone === '') {
            return null;
        }

        return ['lifetimeEarned' => (int) $lifetimeEarned, 'phone' => $phone];
    }
}
