<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Criteria;

use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Modules\Payment\Domain\Enum\PaymentStatus;

/**
 * What to list, as one value instead of six repository arguments.
 *
 * The store is deliberately NOT part of it: store scope is a separate
 * parameter on every repository method, so there is no way to build a query
 * that forgets it (CLAUDE.md section 8).
 */
final readonly class PaymentCriteria
{
    public const int DEFAULT_LIMIT = 50;

    /**
     * @param  list<PaymentStatus>  $statuses  empty means every status
     * @param  bool|null  $needsAttention  true restricts to the reconciliation queue's own filter; null means no filter
     * @param  string|null  $transferReference  exact match: what staff paste from a bank statement line
     */
    public function __construct(
        public array $statuses = [],
        public ?PaymentMethod $method = null,
        public ?bool $needsAttention = null,
        public ?string $transferReference = null,
        public ?string $cursor = null,
        public int $limit = self::DEFAULT_LIMIT,
    ) {}
}
