<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\Entity;

use App\Modules\Order\Domain\Enum\FulfillmentType;
use App\Modules\Order\Domain\Enum\OrderStatus;
use App\Modules\Order\Domain\ValueObject\OrderContact;
use App\Modules\Order\Domain\ValueObject\OrderTotals;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use DateTimeImmutable;

/**
 * An order: what a customer agreed to buy, and where it has got to.
 *
 * Immutable. A transition returns a new instance rather than mutating this
 * one, so an action can compare "before" and "after" when it writes the audit
 * trail and the status history - and so no caller can quietly change the
 * status of an order another part of the request is still reading.
 *
 * The entity carries no Eloquent, no facade and no HTTP (CLAUDE.md sections 3
 * and 16): it is the business rules, and the repository is what turns rows
 * into these and back.
 */
final readonly class Order
{
    /**
     * @param  list<OrderItem>  $items
     * @param  list<OrderStatusChange>  $statusHistory  Empty on the list projection, which does not read it.
     */
    private function __construct(
        public ?string $id,
        public string $storeId,
        public string $code,
        public OrderStatus $status,
        public FulfillmentType $fulfillmentType,
        public ?string $customerId,
        public OrderContact $contact,
        public ?string $deliveryAddressLine,
        public ?string $note,
        public ?DateTimeImmutable $scheduledAt,
        public array $items,
        public OrderTotals $totals,
        public ?DateTimeImmutable $placedAt,
        public ?DateTimeImmutable $createdAt,
        public array $statusHistory,
    ) {}

    /**
     * @param  list<OrderItem>  $items
     * @param  list<OrderStatusChange>  $statusHistory
     */
    public static function create(
        ?string $id,
        string $storeId,
        string $code,
        OrderStatus $status,
        FulfillmentType $fulfillmentType,
        ?string $customerId,
        OrderContact $contact,
        ?string $deliveryAddressLine,
        ?string $note,
        ?DateTimeImmutable $scheduledAt,
        array $items,
        OrderTotals $totals,
        ?DateTimeImmutable $placedAt = null,
        ?DateTimeImmutable $createdAt = null,
        array $statusHistory = [],
    ): self {
        $code = trim($code);

        if ($code === '') {
            // The code is how staff and customers refer to the order out loud;
            // an order without one cannot be worked.
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'An order needs an order code',
                [['field' => 'order_code', 'message' => 'Thiếu mã đơn hàng.']],
            );
        }

        $deliveryAddressLine = $deliveryAddressLine === null ? null : trim($deliveryAddressLine);

        if ($fulfillmentType->needsAddress() && ($deliveryAddressLine === null || $deliveryAddressLine === '')) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A delivery order needs a delivery address',
                [['field' => 'delivery_address_line', 'message' => 'Đơn giao hàng phải có địa chỉ giao.']],
            );
        }

        $note = $note === null ? null : trim($note);

        return new self(
            $id,
            $storeId,
            $code,
            $status,
            $fulfillmentType,
            $customerId,
            $contact,
            $deliveryAddressLine === '' ? null : $deliveryAddressLine,
            $note === '' ? null : $note,
            $scheduledAt,
            array_values($items),
            $totals,
            $placedAt,
            $createdAt,
            array_values($statusHistory),
        );
    }

    /**
     * The same order one step further along.
     *
     * The state machine is enforced here and nowhere else, so every caller -
     * an admin endpoint today, payment confirmation and the expiry job
     * tomorrow - is held to the same rules. An illegal move is a conflict, not
     * a quiet no-op: staff have to know the screen they are looking at is
     * stale (CLAUDE.md section 6).
     *
     * `$at` becomes `placedAt` when this move is the one that puts the order
     * into the service flow, because that is the moment it happened.
     */
    public function transitionTo(OrderStatus $to, ?DateTimeImmutable $at = null): self
    {
        if ($to === $this->status) {
            throw new BusinessRuleViolation(
                ErrorCode::InvalidStateTransition,
                sprintf('Order is already %s', $this->status->value),
                [[
                    'field' => 'to_status',
                    'message' => sprintf('Đơn đang ở trạng thái "%s".', $this->status->label()),
                ]],
            );
        }

        if (! $this->status->canMoveTo($to)) {
            throw new BusinessRuleViolation(
                ErrorCode::InvalidStateTransition,
                sprintf('Cannot move an order from %s to %s', $this->status->value, $to->value),
                [[
                    'field' => 'to_status',
                    'message' => sprintf(
                        'Không thể chuyển đơn từ "%s" sang "%s".',
                        $this->status->label(),
                        $to->label(),
                    ),
                ]],
            );
        }

        return new self(
            $this->id,
            $this->storeId,
            $this->code,
            $to,
            $this->fulfillmentType,
            $this->customerId,
            $this->contact,
            $this->deliveryAddressLine,
            $this->note,
            $this->scheduledAt,
            $this->items,
            $this->totals,
            // Set once, the first time the order enters the service flow, and
            // never rewritten afterwards.
            $this->placedAt ?? ($to->isInService() ? ($at ?? new DateTimeImmutable) : null),
            $this->createdAt,
            $this->statusHistory,
        );
    }

    /**
     * The same order with its recorded history attached, for a detail read.
     *
     * @param  list<OrderStatusChange>  $statusHistory
     */
    public function withStatusHistory(array $statusHistory): self
    {
        return new self(
            $this->id,
            $this->storeId,
            $this->code,
            $this->status,
            $this->fulfillmentType,
            $this->customerId,
            $this->contact,
            $this->deliveryAddressLine,
            $this->note,
            $this->scheduledAt,
            $this->items,
            $this->totals,
            $this->placedAt,
            $this->createdAt,
            array_values($statusHistory),
        );
    }

    /** Lines on the order. Used for the queue row's summary and for reports. */
    public function lineCount(): int
    {
        return count($this->items);
    }

    /** Units ordered across every line - what the kitchen actually makes. */
    public function unitCount(): int
    {
        $units = 0;

        foreach ($this->items as $item) {
            $units += $item->quantity;
        }

        return $units;
    }
}
