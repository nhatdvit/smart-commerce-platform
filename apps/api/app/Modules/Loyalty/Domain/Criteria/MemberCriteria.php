<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Criteria;

/**
 * What to list, as one value instead of three repository arguments - the
 * same shape {@see \App\Modules\Order\Domain\Criteria\CustomerCriteria} takes
 * for the same reason.
 */
final readonly class MemberCriteria
{
    public const int DEFAULT_LIMIT = 50;

    /** @param  string|null  $search  partial match on the phone or the contact name */
    public function __construct(
        public ?string $search = null,
        public ?string $cursor = null,
        public int $limit = self::DEFAULT_LIMIT,
    ) {}
}
