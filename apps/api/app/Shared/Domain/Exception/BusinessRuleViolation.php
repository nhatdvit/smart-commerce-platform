<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

use App\Shared\Presentation\Http\ErrorCode;
use RuntimeException;

/**
 * A business rule said no.
 *
 * Domain and application code throws this instead of an HTTP exception, so the
 * rule stays independent of the transport. The renderer turns it into the
 * documented error envelope with the right status.
 */
class BusinessRuleViolation extends RuntimeException
{
    /**
     * @param  array<int, array{field: string, message: string}>  $details
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function outOfStock(string $message = 'Not enough stock remains for this item'): self
    {
        return new self(ErrorCode::OutOfStock, $message);
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self(
            ErrorCode::InvalidStateTransition,
            sprintf('Cannot move from %s to %s', $from, $to),
        );
    }

    public static function storeScopeDenied(): self
    {
        return new self(
            ErrorCode::StoreScopeDenied,
            'You are not a member of this store',
        );
    }
}
