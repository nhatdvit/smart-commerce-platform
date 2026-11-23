<?php

declare(strict_types=1);

namespace App\Modules\Order\Application\DTO;

use App\Modules\Order\Domain\Enum\FulfillmentType;
use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;

/**
 * What the checkout screen submitted, validated for shape but not yet
 * checked against the live menu or the store's enabled payment methods -
 * {@see \App\Modules\Order\Application\Action\CreateOrder} does that.
 */
final readonly class CreateOrderInput
{
    /** @param  list<CreateOrderLineInput>  $items */
    public function __construct(
        public FulfillmentType $fulfillmentType,
        public PaymentMethod $paymentMethod,
        public string $contactName,
        public string $contactPhone,
        public ?string $contactEmail,
        public ?string $deliveryAddressLine,
        public ?DateTimeImmutable $scheduledAt,
        public ?string $note,
        public array $items,
        /** A consent check against the price the client last showed, never an input to pricing. */
        public ?Money $expectedTotalAmount,
    ) {}
}
