<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure\Persistence;

use App\Modules\Pricing\Domain\Contract\CouponRepository;
use App\Modules\Pricing\Domain\Criteria\CouponCriteria;
use App\Modules\Pricing\Domain\Entity\Coupon;
use App\Modules\Pricing\Domain\Enum\CouponStatus;
use App\Modules\Pricing\Infrastructure\Persistence\Model\Coupon as CouponModel;
use App\Shared\Domain\ValueObject\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent implementation of the coupon seam.
 *
 * The only class in the module that knows the `coupons` table exists, and
 * where rows become domain objects (CLAUDE.md section 16). Every read is
 * store-scoped by construction.
 */
final class EloquentCouponRepository implements CouponRepository
{
    /** Hard ceiling on a page, whatever the client asks for (CLAUDE.md section 8). */
    public const int MAX_LIMIT = 100;

    public function findInStore(string $storeId, string $couponId): ?Coupon
    {
        $row = $this->baseQuery($storeId)->whereKey($couponId)->first();

        return $row === null ? null : $this->toEntity($row);
    }

    public function paginate(string $storeId, CouponCriteria $criteria): array
    {
        $limit = max(1, min($criteria->limit, self::MAX_LIMIT));
        $query = $this->baseQuery($storeId);

        if ($criteria->statuses !== []) {
            $query->whereIn('status', array_map(
                static fn (CouponStatus $status): string => $status->value,
                $criteria->statuses,
            ));
        }

        if ($criteria->search !== null && trim($criteria->search) !== '') {
            $needle = '%'.trim($criteria->search).'%';
            $query->where(function (Builder $scoped) use ($needle): void {
                $scoped->where('code', 'ilike', $needle)
                    ->orWhere('description', 'ilike', $needle);
            });
        }

        // Cursor pagination over the primary key: UUIDv7 keys are
        // time-ordered, so "id descending" is "newest first" and a page cannot
        // shift under a client that is creating codes.
        $after = $this->decodeCursor($criteria->cursor);

        if ($after !== null) {
            $query->where('id', '<', $after);
        }

        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $page = $hasMore ? $rows->take($limit) : $rows;
        $last = $page->last();

        return [
            'items' => array_values($page->map(fn (CouponModel $row): Coupon => $this->toEntity($row))->all()),
            'next_cursor' => $hasMore && $last instanceof CouponModel ? $this->encodeCursor($last->id) : null,
            'has_more' => $hasMore,
        ];
    }

    public function codeExists(string $storeId, string $code, ?string $exceptCouponId = null): bool
    {
        // Compared on the stored form, which the entity has already upper-cased,
        // so this check and the unique index can never disagree.
        $query = CouponModel::query()
            ->where('store_id', $storeId)
            ->where('code', strtoupper(trim($code)));

        if ($exceptCouponId !== null) {
            $query->whereKeyNot($exceptCouponId);
        }

        return $query->exists();
    }

    public function create(Coupon $coupon): Coupon
    {
        $row = CouponModel::query()->create($this->columns($coupon));

        return $this->toEntity($row->refresh());
    }

    public function update(string $couponId, Coupon $coupon): Coupon
    {
        $row = $this->baseQuery($coupon->storeId)->whereKey($couponId)->sole();

        // The code and the redemption counter are not in the editable set: one
        // is already in customers' hands, the other is the system's record.
        $row->fill($this->editableColumns($coupon))->save();

        return $this->toEntity($row->refresh());
    }

    /** @return Builder<CouponModel> */
    private function baseQuery(string $storeId): Builder
    {
        return CouponModel::query()->where('store_id', $storeId);
    }

    /** @return array<string, mixed> */
    private function columns(Coupon $coupon): array
    {
        return array_merge($this->editableColumns($coupon), [
            'store_id' => $coupon->storeId,
            'code' => $coupon->code,
            'redeemed_count' => $coupon->redeemedCount,
            'currency' => Money::CURRENCY_VND,
        ]);
    }

    /** @return array<string, mixed> */
    private function editableColumns(Coupon $coupon): array
    {
        return [
            'description' => $coupon->description,
            'discount_type' => $coupon->discountType->value,
            'discount_value' => $coupon->discountValue,
            'max_discount_amount' => $coupon->maxDiscount?->amount,
            'min_order_amount' => $coupon->minOrder->amount,
            'usage_limit' => $coupon->usageLimit,
            'starts_at' => $coupon->startsAt,
            'ends_at' => $coupon->endsAt,
            'status' => $coupon->status->value,
        ];
    }

    private function toEntity(CouponModel $row): Coupon
    {
        return Coupon::create(
            $row->id,
            $row->store_id,
            $row->code,
            $row->description,
            $row->discount_type,
            $row->discount_value,
            $row->max_discount_amount === null ? null : Money::vnd($row->max_discount_amount),
            Money::vnd($row->min_order_amount),
            $row->usage_limit,
            $row->redeemed_count,
            $row->starts_at,
            $row->ends_at,
            $row->status,
            $row->created_at,
        );
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
