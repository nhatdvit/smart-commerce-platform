<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Contract;

use App\Modules\Order\Domain\Criteria\CustomerCriteria;
use App\Modules\Order\Domain\Entity\CustomerSummary;

/**
 * Persistence seam for the guest-customer directory: orders, grouped by the
 * contact phone number they were placed under.
 *
 * Store-scoped throughout, the same convention as {@see OrderRepository}: a
 * cross-store read is impossible by construction (CLAUDE.md section 8).
 */
interface CustomerDirectory
{
    /** @return array{items: list<CustomerSummary>, next_cursor: string|null, has_more: bool} */
    public function paginate(string $storeId, CustomerCriteria $criteria): array;
}
