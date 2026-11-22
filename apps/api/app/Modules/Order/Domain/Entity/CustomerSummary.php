<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Entity;

use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;

/**
 * One guest customer, as their order history adds up.
 *
 * There is no registered-account module yet (CLAUDE.md section 5 still lists
 * `Customer` as a future module), so a "customer" here is simply every order
 * placed under one contact phone number - the closest thing to an identity a
 * guest checkout has. Deliberately carries no loyalty data: points, tiers and
 * membership belong to the Loyalty module, which needs a real customer
 * identity and a real checkout to earn from before either number means
 * anything (CLAUDE.md delivery stage 4).
 *
 * A pure read projection, not a domain entity with behaviour - there is
 * nothing here to validate or transition, only orders to add up.
 */
final readonly class CustomerSummary
{
    public function __construct(
        public string $phone,
        /** The contact name on this phone's most recent order - the one a cashier would recognise today. */
        public string $name,
        public ?string $email,
        public int $orderCount,
        /** Sums only orders whose payment is PAID - a cancelled or uncollected order was never actually spent. */
        public Money $totalSpent,
        public ?DateTimeImmutable $lastOrderAt,
    ) {}
}
