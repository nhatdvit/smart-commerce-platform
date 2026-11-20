<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Enum;

/**
 * How the customer receives the order.
 *
 * Only the two MVP modes. Dine-in QR ordering is not in scope, and
 * appointment-based services need their own booking model entirely
 * (CLAUDE.md sections 1 and 15), so neither is invented here.
 */
enum FulfillmentType: string
{
    case Pickup = 'PICKUP';
    case Delivery = 'DELIVERY';

    /** Delivery needs somewhere to deliver to. */
    public function needsAddress(): bool
    {
        return $this === self::Delivery;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Nhận tại quán',
            self::Delivery => 'Giao hàng',
        };
    }
}
