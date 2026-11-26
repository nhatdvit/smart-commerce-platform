<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Contract;

use App\Modules\Order\Domain\Criteria\OrderCriteria;
use App\Modules\Order\Domain\Entity\Order;
use App\Modules\Order\Domain\Enum\OrderStatus;

/**
 * Persistence seam for orders.
 *
 * Every method takes a store id, so there is no way to ask this repository a
 * question that is not scoped to one store - cross-store reads are impossible
 * by construction rather than by remembering a where clause (CLAUDE.md
 * section 8).
 *
 * Nothing here deletes anything. An order is financial history: it is
 * cancelled or it expires, and the row stays (section 7).
 */
interface OrderRepository
{
    /**
     * One order with its lines and its recorded status history.
     *
     * Null when the order does not exist, or belongs to another store - the
     * caller must not be able to tell those apart.
     */
    public function findInStore(string $storeId, string $orderId): ?Order;

    /**
     * A page of this store's orders, with their lines.
     *
     * The lines come along because the queue row shows what was ordered; one
     * page is tens of orders, so this is a bounded eager load rather than a
     * reason to invent a second projection.
     *
     * @return array{items: list<Order>, next_cursor: string|null, has_more: bool}
     */
    public function paginate(string $storeId, OrderCriteria $criteria): array;

    /**
     * A page of one customer's own orders in this store, newest first by
     * default (unlike {@see paginate}, where the oldest open order is the
     * most urgent one) - a customer reading their own history wants to see
     * what they just placed, not an operations queue.
     *
     * The customer id is its own parameter, never a field on the criteria,
     * for the same reason the store id is: there must be no way to build a
     * call that forgets to scope it.
     *
     * @return array{items: list<Order>, next_cursor: string|null, has_more: bool}
     */
    public function paginateForCustomer(string $storeId, string $customerId, OrderCriteria $criteria): array;

    /**
     * The same order, read inside the caller's transaction with the row locked.
     *
     * Two staff members advancing one order at the same moment is an ordinary
     * event in a busy shop. Without the lock both would read PREPARING and
     * both would write READY, and the second write would record a transition
     * that never happened (CLAUDE.md section 16).
     *
     * Must be called inside a transaction.
     */
    public function findForUpdate(string $storeId, string $orderId): ?Order;

    /**
     * Persists an order that has already been moved to its new status, and
     * records the move.
     *
     * One call, so the status column and the history row can never disagree:
     * they are written in the same statement pair inside the caller's
     * transaction (CLAUDE.md section 6).
     *
     * @param  Order  $order  already transitioned; its current status is the new one
     * @param  OrderStatus  $from  the status it moved away from
     */
    public function recordTransition(
        Order $order,
        OrderStatus $from,
        ?string $actorId,
        ?string $reason,
    ): Order;

    /**
     * How many orders this store currently has in each open status.
     *
     * Open statuses only, which is what a work queue needs and what the
     * partial index covers: counting every order a store has ever served
     * would grow without bound and answer a question nobody asked.
     *
     * @return array<string, int> status value => count, every open status present
     */
    public function openStatusCounts(string $storeId): array;

    /**
     * Every order in this store currently waiting on an unverified bank
     * transfer - ids only, so the caller can ask Payment's exported read
     * model which of them a customer has actually reported (CLAUDE.md
     * section 3: this module does not touch the payments table itself).
     *
     * @return list<string>
     */
    public function pendingPaymentOrderIds(string $storeId): array;
}
