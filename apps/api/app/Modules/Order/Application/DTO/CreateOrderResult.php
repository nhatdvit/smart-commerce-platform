<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\DTO;

/**
 * What checkout hands back to the controller: enough to look the order up
 * again, plus the one-time token a guest needs to read it without a session.
 */
final readonly class CreateOrderResult
{
    public function __construct(
        public string $orderId,
        public string $orderCode,
        /** Null for a signed-in customer's order - it is read through their session instead. */
        public ?string $guestAccessToken,
    ) {}
}
