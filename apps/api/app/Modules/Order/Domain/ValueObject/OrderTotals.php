<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\ValueObject;

use App\Modules\Order\Domain\Entity\OrderItem;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Domain\ValueObject\Money;
use App\Shared\Presentation\Http\ErrorCode;

/**
 * What the order costs, and the one place that arithmetic is defined.
 *
 * `total` is never passed in: it is derived, here, from the components. The
 * same formula is written as a CHECK constraint on `orders`, so a receipt that
 * does not add up cannot be stored by this code, by a seeder, or by a future
 * service on the same table (CLAUDE.md sections 6 and 7).
 *
 * Every amount is exact integer dong through Money - no float ever touches an
 * order total.
 */
final readonly class OrderTotals
{
    private function __construct(
        public Money $subtotal,
        public Money $discount,
        public Money $deliveryFee,
        public Money $tax,
        public Money $total,
    ) {}

    public static function create(
        Money $subtotal,
        ?Money $discount = null,
        ?Money $deliveryFee = null,
        ?Money $tax = null,
    ): self {
        $discount ??= Money::zero();
        $deliveryFee ??= Money::zero();
        // Zero until tax rules are decided (CLAUDE.md section 15). Carried
        // through the arithmetic so answering that question changes nothing
        // here.
        $tax ??= Money::zero();

        foreach ([
            'subtotal' => $subtotal,
            'discount' => $discount,
            'delivery_fee' => $deliveryFee,
            'tax' => $tax,
        ] as $field => $amount) {
            if ($amount->isNegative()) {
                throw new BusinessRuleViolation(
                    ErrorCode::ValidationFailed,
                    'Order amounts cannot be negative',
                    [['field' => $field, 'message' => 'Số tiền phải là số nguyên đồng, không âm.']],
                );
            }
        }

        // A discount can take an order to zero but never below it: a shop does
        // not owe a customer money for ordering breakfast.
        if ($discount->amount > $subtotal->amount) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A discount cannot exceed the order subtotal',
                [['field' => 'discount', 'message' => 'Giảm giá không thể lớn hơn tổng tiền hàng.']],
            );
        }

        return new self(
            $subtotal,
            $discount,
            $deliveryFee,
            $tax,
            $subtotal->minus($discount)->plus($deliveryFee)->plus($tax),
        );
    }

    /**
     * Totals summed from the lines, for whoever is writing the order.
     *
     * Used on the way in - the demo seeder today, checkout when it lands - so
     * the subtotal provably matches the lines rather than being asserted
     * alongside them. The read path does not re-derive it: the stored row is
     * what the customer agreed to, and the database already guarantees the
     * components add up.
     *
     * @param  list<OrderItem>  $items
     */
    public static function fromLines(
        array $items,
        ?Money $discount = null,
        ?Money $deliveryFee = null,
        ?Money $tax = null,
    ): self {
        $subtotal = Money::zero();

        foreach ($items as $item) {
            $subtotal = $subtotal->plus($item->lineTotal);
        }

        return self::create($subtotal, $discount, $deliveryFee, $tax);
    }

    /**
     * Totals as stored, with the stored total trusted.
     *
     * The repository uses this when mapping a row back: recomputing would
     * silently "fix" a historical receipt, and a receipt is a record of what
     * was agreed, not a formula to re-evaluate.
     */
    public static function stored(
        Money $subtotal,
        Money $discount,
        Money $deliveryFee,
        Money $tax,
        Money $total,
    ): self {
        return new self($subtotal, $discount, $deliveryFee, $tax, $total);
    }
}
