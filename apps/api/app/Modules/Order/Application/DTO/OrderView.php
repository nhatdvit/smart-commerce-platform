<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\DTO;

use App\Modules\Order\Domain\Entity\Order;
use App\Modules\Payment\Application\DTO\PaymentSnapshot;

/**
 * An order together with how it is being paid, ready to be rendered.
 *
 * The two are joined here, in the application layer, rather than being one
 * entity: order state and payment state are separate state machines owned by
 * separate modules (CLAUDE.md section 6), and the only thing that legitimately
 * holds both at once is a view.
 *
 * `payment` is nullable because an order whose payment row is missing is a
 * real, visible condition - something went wrong during checkout - and the
 * screen should say so rather than imply the order is unpaid cash.
 */
final readonly class OrderView
{
    public function __construct(
        public Order $order,
        public ?PaymentSnapshot $payment,
    ) {}
}
