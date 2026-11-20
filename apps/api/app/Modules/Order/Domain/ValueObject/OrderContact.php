<?php

declare(strict_types=1);

namespace App\Modules\Order\Domain\ValueObject;

use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Domain\ValueObject\DisplayName;
use App\Shared\Presentation\Http\ErrorCode;

/**
 * Who to contact about this order, as it was given at checkout.
 *
 * A snapshot, not a reference. A guest has no account to look up, and a
 * signed-in customer may rename themselves tomorrow; neither must change who
 * the kitchen calls about the order in front of them (CLAUDE.md section 6).
 */
final readonly class OrderContact
{
    private function __construct(
        public string $name,
        public string $phone,
        public ?string $email,
    ) {}

    public static function create(string $name, string $phone, ?string $email = null): self
    {
        $name = DisplayName::normalise($name);
        $phone = trim($phone);

        if ($name === '') {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'An order needs a contact name',
                [['field' => 'contact.name', 'message' => 'Vui lòng nhập tên người nhận.']],
            );
        }

        if ($phone === '') {
            // The phone number is how a shop reaches a customer whose order is
            // ready; an order without one cannot be served.
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'An order needs a contact phone number',
                [['field' => 'contact.phone', 'message' => 'Vui lòng nhập số điện thoại.']],
            );
        }

        $email = $email === null ? null : trim($email);

        return new self($name, $phone, $email === '' ? null : $email);
    }
}
