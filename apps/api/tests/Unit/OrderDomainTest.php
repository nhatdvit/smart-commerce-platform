<?php

declare(strict_types=1);

use App\Modules\Order\Domain\Entity\Order;
use App\Modules\Order\Domain\Entity\OrderItem;
use App\Modules\Order\Domain\Enum\FulfillmentType;
use App\Modules\Order\Domain\Enum\OrderStatus;
use App\Modules\Order\Domain\ValueObject\OrderContact;
use App\Modules\Order\Domain\ValueObject\OrderTotals;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Domain\ValueObject\Money;

/*
 * Order rules, with no database and no framework.
 *
 * The state machine and the money arithmetic have to hold wherever an order
 * comes from - an admin endpoint, payment confirmation, the expiry job, an
 * import - which is why they live in the domain and can be exercised without
 * booting anything (CLAUDE.md sections 3 and 12).
 */

function orderLine(int $unitPrice = 25_000, int $quantity = 2): OrderItem
{
    return OrderItem::create(
        null,
        null,
        null,
        'Bánh mì pate trứng',
        'Mặc định',
        $quantity,
        Money::vnd($unitPrice),
    );
}

function anOrder(
    OrderStatus $status = OrderStatus::Placed,
    ?OrderTotals $totals = null,
    FulfillmentType $type = FulfillmentType::Pickup,
    ?string $address = null,
): Order {
    $lines = [orderLine()];

    return Order::create(
        null,
        '01930000-0000-7000-8000-000000000001',
        'SCP-20261120-0001',
        $status,
        $type,
        null,
        OrderContact::create('Nguyễn Minh Anh', '0903123456'),
        $address,
        null,
        null,
        $lines,
        $totals ?? OrderTotals::fromLines($lines),
    );
}

it('derives the line total from the price, the modifiers and the quantity', function (): void {
    $line = OrderItem::create(
        null,
        null,
        null,
        'Xôi gà',
        'Mặc định',
        3,
        Money::vnd(30_000),
        Money::vnd(5_000),
    );

    // (30.000 + 5.000) x 3. Exact integer dong, never a float.
    expect($line->lineTotal->amount)->toBe(105_000);
});

it('refuses a line with no quantity', function (): void {
    expect(fn (): OrderItem => orderLine(quantity: 0))
        ->toThrow(BusinessRuleViolation::class);
});

it('names a dish that was sold without a size', function (): void {
    $line = OrderItem::create(null, null, null, 'Cà phê sữa', '  ', 1, Money::vnd(18_000));

    expect($line->variantName)->toBe('Mặc định');
});

it('derives the order total the same way the database checks it', function (): void {
    $totals = OrderTotals::create(
        Money::vnd(100_000),
        Money::vnd(10_000),
        Money::vnd(15_000),
    );

    // subtotal - discount + delivery fee + tax
    expect($totals->total->amount)->toBe(105_000)
        ->and($totals->tax->amount)->toBe(0);
});

it('sums the subtotal from the lines', function (): void {
    $totals = OrderTotals::fromLines([orderLine(25_000, 2), orderLine(18_000, 1)]);

    expect($totals->subtotal->amount)->toBe(68_000)
        ->and($totals->total->amount)->toBe(68_000);
});

it('refuses a discount larger than the order', function (): void {
    // A shop does not owe a customer money for ordering breakfast.
    expect(fn (): OrderTotals => OrderTotals::create(Money::vnd(50_000), Money::vnd(60_000)))
        ->toThrow(BusinessRuleViolation::class);
});

it('refuses a delivery order with no address', function (): void {
    expect(fn (): Order => anOrder(type: FulfillmentType::Delivery))
        ->toThrow(BusinessRuleViolation::class);

    expect(anOrder(type: FulfillmentType::Delivery, address: '45 Lê Lợi')->deliveryAddressLine)
        ->toBe('45 Lê Lợi');
});

it('walks the service flow one step at a time', function (): void {
    $order = anOrder(OrderStatus::Placed)
        ->transitionTo(OrderStatus::Confirmed)
        ->transitionTo(OrderStatus::Preparing)
        ->transitionTo(OrderStatus::Ready)
        ->transitionTo(OrderStatus::Completed);

    expect($order->status)->toBe(OrderStatus::Completed);
});

it('refuses to skip a step', function (): void {
    expect(fn (): Order => anOrder(OrderStatus::Placed)->transitionTo(OrderStatus::Ready))
        ->toThrow(BusinessRuleViolation::class);
});

it('refuses to move backwards', function (): void {
    // Correcting a mistaken move is a new, recorded decision - never an undo.
    expect(fn (): Order => anOrder(OrderStatus::Ready)->transitionTo(OrderStatus::Preparing))
        ->toThrow(BusinessRuleViolation::class);
});

it('refuses to move a finished order anywhere', function (): void {
    foreach ([OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Expired] as $terminal) {
        expect($terminal->isTerminal())->toBeTrue()
            ->and($terminal->allowedNext())->toBe([]);

        expect(fn (): Order => anOrder($terminal)->transitionTo(OrderStatus::Confirmed))
            ->toThrow(BusinessRuleViolation::class);
    }
});

it('stamps the placed time once, when the order enters the service flow', function (): void {
    $pending = anOrder(OrderStatus::PendingPayment);

    expect($pending->placedAt)->toBeNull();

    $placed = $pending->transitionTo(OrderStatus::Placed);
    $confirmed = $placed->transitionTo(OrderStatus::Confirmed);

    expect($placed->placedAt)->not->toBeNull()
        // Set once and never rewritten: it is when the order was placed, not
        // when it was last touched.
        ->and($confirmed->placedAt)->toBe($placed->placedAt);
});

it('keeps no placed time for an order that was never placed', function (): void {
    $cancelled = anOrder(OrderStatus::PendingPayment)->transitionTo(OrderStatus::Cancelled);

    expect($cancelled->placedAt)->toBeNull();
});

it('lets the kitchen own exactly the two preparation steps', function (): void {
    expect(OrderStatus::Preparing->isKitchenStep())->toBeTrue()
        ->and(OrderStatus::Ready->isKitchenStep())->toBeTrue()
        // Handing an order over and cancelling one touch the money and the
        // customer, so they are not the kitchen's to make.
        ->and(OrderStatus::Completed->isKitchenStep())->toBeFalse()
        ->and(OrderStatus::Cancelled->isKitchenStep())->toBeFalse();
});

it('requires a reason only where one is owed', function (): void {
    expect(OrderStatus::Cancelled->requiresReason())->toBeTrue()
        ->and(OrderStatus::Completed->requiresReason())->toBeFalse();
});

it('counts lines and units separately', function (): void {
    $lines = [orderLine(25_000, 2), orderLine(18_000, 3)];

    $order = Order::create(
        null,
        '01930000-0000-7000-8000-000000000001',
        'SCP-20261120-0002',
        OrderStatus::Placed,
        FulfillmentType::Pickup,
        null,
        OrderContact::create('Khách lẻ', '0900000000'),
        null,
        null,
        null,
        $lines,
        OrderTotals::fromLines($lines),
    );

    // Two rows on the receipt, five things for the kitchen to make.
    expect($order->lineCount())->toBe(2)
        ->and($order->unitCount())->toBe(5);
});

it('needs a contact the shop can actually reach', function (): void {
    expect(fn (): OrderContact => OrderContact::create('  ', '0903123456'))
        ->toThrow(BusinessRuleViolation::class);

    expect(fn (): OrderContact => OrderContact::create('Nguyễn Minh Anh', ' '))
        ->toThrow(BusinessRuleViolation::class);
});
