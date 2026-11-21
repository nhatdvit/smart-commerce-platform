<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Contract;

use App\Modules\Pricing\Domain\Criteria\CouponCriteria;
use App\Modules\Pricing\Domain\Entity\Coupon;

/**
 * Persistence seam for discount codes.
 *
 * Store-scoped throughout: every method takes a store id, so a cross-store
 * read is impossible by construction rather than by remembering a where
 * clause (CLAUDE.md section 8).
 *
 * Nothing deletes. A code that has been redeemed is part of what an order was
 * charged; retiring it is a status, not a DELETE (section 7).
 */
interface CouponRepository
{
    /** Null when the code does not exist, or belongs to another store. */
    public function findInStore(string $storeId, string $couponId): ?Coupon;

    /**
     * A page of this store's codes, newest first.
     *
     * @return array{items: list<Coupon>, next_cursor: string|null, has_more: bool}
     */
    public function paginate(string $storeId, CouponCriteria $criteria): array;

    /**
     * Whether this store already has a code with this text.
     *
     * Codes are stored upper-case, so this is the case-insensitive question.
     * Archived codes still count: reusing a retired code would attach new
     * orders to a campaign that ended, and customers holding the old slip
     * would be right to be confused.
     */
    public function codeExists(string $storeId, string $code, ?string $exceptCouponId = null): bool;

    /** Returns the coupon as stored, with its generated id. */
    public function create(Coupon $coupon): Coupon;

    /** Replaces the editable fields of a stored coupon. */
    public function update(string $couponId, Coupon $coupon): Coupon;
}
