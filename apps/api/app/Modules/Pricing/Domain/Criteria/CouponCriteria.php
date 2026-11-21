<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain\Criteria;

use App\Modules\Pricing\Domain\Enum\CouponStatus;

/**
 * What to list, as one value instead of five repository arguments.
 *
 * The store is deliberately not part of it: store scope is a separate
 * parameter on every repository method, so there is no way to build a query
 * that forgets it (CLAUDE.md section 8).
 */
final readonly class CouponCriteria
{
    public const int DEFAULT_LIMIT = 50;

    /**
     * @param  list<CouponStatus>  $statuses  empty means every status
     * @param  string|null  $search  partial match on the code or the description
     */
    public function __construct(
        public array $statuses = [],
        public ?string $search = null,
        public ?string $cursor = null,
        public int $limit = self::DEFAULT_LIMIT,
    ) {}
}
