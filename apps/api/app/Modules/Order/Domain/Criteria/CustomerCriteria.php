<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Criteria;

/**
 * What to list, as one value instead of three repository arguments.
 *
 * The store is deliberately NOT part of it, the same reasoning as
 * {@see OrderCriteria}: store scope is a separate parameter on every
 * repository method, so there is no way to build a query that forgets it
 * (CLAUDE.md section 8).
 */
final readonly class CustomerCriteria
{
    public const int DEFAULT_LIMIT = 50;

    /** @param  string|null  $search  partial match on the phone or the contact name */
    public function __construct(
        public ?string $search = null,
        public ?string $cursor = null,
        public int $limit = self::DEFAULT_LIMIT,
    ) {}
}
