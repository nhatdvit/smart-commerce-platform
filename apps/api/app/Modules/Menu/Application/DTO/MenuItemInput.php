<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application\DTO;

use App\Shared\Domain\ValueObject\Money;

/** One line of a menu replacement, already parsed out of transport types. */
final readonly class MenuItemInput
{
    public function __construct(
        public string $variantId,
        public Money $sellingPrice,
        public bool $isAvailable = true,
        public ?int $availableQuantity = null,
        public ?int $dailyCapacity = null,
        public int $sortOrder = 0,
        /** Present when the caller is editing a line that already exists. */
        public ?string $id = null,
        /**
         * Whether the caller said anything about today's portions.
         *
         * An omitted field must leave the kitchen's counts alone, which is not
         * the same as being told to clear them - and null alone cannot tell the
         * two apart. A menu edit that silently reset every portion count would
         * be the kind of bug nobody notices until service.
         */
        public bool $quantityGiven = false,
        public bool $capacityGiven = false,
    ) {}
}
