<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Http;

/**
 * Stable, machine-readable error codes.
 *
 * The HTTP status carries the class of failure; this code carries the reason.
 * Clients branch on the code, never on the message. Adding a case is safe;
 * repurposing an existing one is a breaking API change (CLAUDE.md sections 3 and 8).
 */
enum ErrorCode: string
{
    // 400 / 422 - request shape and business validation
    case ValidationFailed = 'VALIDATION_FAILED';
    case InvalidStoreContext = 'INVALID_STORE_CONTEXT';

    // 401 / 403 - authentication and authorization
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case StoreScopeDenied = 'STORE_SCOPE_DENIED';

    // 404
    case ResourceNotFound = 'RESOURCE_NOT_FOUND';

    // 405
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';

    // 409 - conflicts and concurrency
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
    /** Same key, still running. The client should retry, not change the key. */
    case RequestInProgress = 'REQUEST_IN_PROGRESS';
    case InvalidStateTransition = 'INVALID_STATE_TRANSITION';
    case OutOfStock = 'OUT_OF_STOCK';
    case PriceChanged = 'PRICE_CHANGED';
    case MenuUnavailable = 'MENU_UNAVAILABLE';
    case PaymentAlreadyConfirmed = 'PAYMENT_ALREADY_CONFIRMED';
    case PaymentAmountMismatch = 'PAYMENT_AMOUNT_MISMATCH';

    // 429
    case RateLimited = 'RATE_LIMITED';

    // 500 / 503
    case InternalError = 'INTERNAL_ERROR';
    case DependencyUnavailable = 'DEPENDENCY_UNAVAILABLE';

    /** The status an error of this kind is reported with. */
    public function httpStatus(): int
    {
        return match ($this) {
            self::ValidationFailed => 422,
            self::InvalidStoreContext => 400,
            self::Unauthenticated => 401,
            self::Forbidden, self::StoreScopeDenied => 403,
            self::ResourceNotFound => 404,
            self::MethodNotAllowed => 405,
            self::IdempotencyKeyReused,
            self::RequestInProgress,
            self::InvalidStateTransition,
            self::OutOfStock,
            self::PriceChanged,
            self::MenuUnavailable,
            self::PaymentAlreadyConfirmed,
            self::PaymentAmountMismatch => 409,
            self::RateLimited => 429,
            self::DependencyUnavailable => 503,
            self::InternalError => 500,
        };
    }
}
