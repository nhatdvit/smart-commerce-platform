<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObject;

use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;

/**
 * The code a bank statement line is matched back to an order by.
 *
 * Generated, never chosen by the customer: a hand-typed reference is a typo
 * waiting to happen, and a mismatched reference is exactly the exception this
 * whole module exists to catch (`UNKNOWN_REFERENCE`). Derived from the order
 * code so staff can usually tell which order it is just by looking at it.
 *
 * Deterministic given its inputs - no randomness in this class. The caller
 * supplies a disambiguator when a first candidate collides (the database's
 * unique index is the actual guard; see GenerateBankTransferInstructions),
 * which keeps this value object a pure function of what it is handed.
 */
final readonly class TransferReference
{
    public const int MAX_LENGTH = 32;

    /** Matches the CHECK constraint on `payments.transfer_reference`. */
    private const string FORMAT = '/^[A-Z0-9]{6,32}$/';

    private function __construct(public string $value) {}

    /**
     * Builds a reference from an order code and a configured prefix.
     *
     * `SCP-20261121-0042` becomes `SCP202611210042`: the prefix repeated would
     * be noise (it is already the order code's own prefix in every deployment
     * this platform has), so only the date and sequence travel, with every
     * non-alphanumeric character dropped. `disambiguator` is appended only when
     * a first attempt collided, so the common case stays the short, readable
     * code a cashier can match against a statement by eye.
     */
    public static function fromOrderCode(string $prefix, string $orderCode, string $disambiguator = ''): self
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?? '');
        $body = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $orderCode) ?? '');
        $disambiguator = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $disambiguator) ?? '');

        $candidate = $prefix.$body.$disambiguator;

        // Longer than the column allows: keep the prefix and the disambiguator
        // whole - they are what makes a retry different from the attempt
        // before it - and trim the body, which is the part a human reads for
        // context rather than the part a database needs to be unique.
        if (strlen($candidate) > self::MAX_LENGTH) {
            $keep = self::MAX_LENGTH - strlen($prefix) - strlen($disambiguator);
            $body = $keep > 0 ? substr($body, 0, $keep) : '';
            $candidate = $prefix.$body.$disambiguator;
        }

        return self::create($candidate);
    }

    public static function create(string $value): self
    {
        $value = strtoupper(trim($value));

        if (preg_match(self::FORMAT, $value) !== 1) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A transfer reference must be 6 to 32 characters of A-Z and 0-9',
                [['field' => 'transfer_reference', 'message' => 'Mã tham chiếu không hợp lệ.']],
            );
        }

        return new self($value);
    }
}
