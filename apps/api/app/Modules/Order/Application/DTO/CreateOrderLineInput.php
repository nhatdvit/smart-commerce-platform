<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\DTO;

/** One requested line, before it is checked against the live menu. */
final readonly class CreateOrderLineInput
{
    public function __construct(
        public string $menuItemId,
        public int $quantity,
        public ?string $note,
    ) {}
}
