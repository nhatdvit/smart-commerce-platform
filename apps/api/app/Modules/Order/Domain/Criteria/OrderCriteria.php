<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Criteria;

use App\Modules\Order\Domain\Enum\FulfillmentType;
use App\Modules\Order\Domain\Enum\OrderStatus;
use DateTimeImmutable;

/**
 * What to list, as one value instead of nine repository arguments.
 *
 * A domain value rather than a request DTO: the same question - "this store's
 * open orders, oldest first" - is asked by the operations screen today, and by
 * the kitchen display and the expiry job later, none of which involve HTTP.
 *
 * The store is deliberately NOT part of it. Store scope is a separate
 * parameter on every repository method, so there is no way to build a query
 * that forgets it (CLAUDE.md section 8).
 */
final readonly class OrderCriteria
{
    public const int DEFAULT_LIMIT = 50;

    /**
     * @param  list<OrderStatus>  $statuses  empty means every status
     * @param  string|null  $orderCode  exact match: what staff type when a code is read aloud
     * @param  string|null  $search  partial match on the code, the contact, or a line's product name
     * @param  bool  $oldestFirst  the queue default: the oldest unserved order is the most urgent
     */
    public function __construct(
        public array $statuses = [],
        public ?string $orderCode = null,
        public ?string $search = null,
        public ?FulfillmentType $fulfillmentType = null,
        public ?DateTimeImmutable $placedFrom = null,
        public ?DateTimeImmutable $placedTo = null,
        public bool $oldestFirst = true,
        public ?string $cursor = null,
        public int $limit = self::DEFAULT_LIMIT,
    ) {}
}
