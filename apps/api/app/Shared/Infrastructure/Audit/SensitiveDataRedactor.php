<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit;

/**
 * Removes secrets and personal data from a payload before it is persisted.
 *
 * The audit trail is append-only and kept for a long time, so anything that
 * slips in is effectively permanent (CLAUDE.md §8, §10). Redaction therefore
 * happens here, at the single point of entry, rather than being left to each
 * caller to remember.
 *
 * Two levels:
 *
 *  - **Redacted** — replaced entirely. Credentials and tokens have no audit
 *    value at all; knowing a password changed is useful, knowing the password
 *    is a liability.
 *  - **Masked** — last four characters kept. A bank account or phone number is
 *    often the only way to tell two records apart during reconciliation, so
 *    discarding it completely would make the trail useless; keeping it whole
 *    would put account numbers in a long-lived table.
 *
 * Matching is on the key name, case-insensitive and substring-based, so
 * `password`, `new_password` and `passwordConfirmation` are all caught.
 */
final class SensitiveDataRedactor
{
    public const string REDACTED = '[REDACTED]';

    /**
     * Substrings that mean "never store this".
     *
     * @var list<string>
     */
    private const array REDACT = [
        'password',
        'secret',
        'token',
        'authorization',
        'api_key',
        'apikey',
        'private_key',
        'credential',
        'cvv',
        'cvc',
        'pin',
        'otp',
    ];

    /**
     * Substrings that mean "keep only enough to identify it".
     *
     * @var list<string>
     */
    private const array MASK = [
        'account_number',
        'accountnumber',
        'card_number',
        'cardnumber',
        'phone',
        'email',
    ];

    private const int VISIBLE_SUFFIX = 4;

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function redact(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            $name = is_string($key) ? $key : '';

            if ($this->matches($name, self::REDACT)) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                // Recurse before masking: a nested structure under a masked key
                // still needs its own keys examined.
                $clean[$key] = $this->redact($value);

                continue;
            }

            if ($this->matches($name, self::MASK)) {
                $clean[$key] = $this->mask($value);

                continue;
            }

            $clean[$key] = $this->scalar($value);
        }

        return $clean;
    }

    /** @param list<string> $needles */
    private function matches(string $key, array $needles): bool
    {
        if ($key === '') {
            return false;
        }

        $lower = mb_strtolower($key);

        foreach ($needles as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function mask(mixed $value): string
    {
        if ($value === null) {
            return self::REDACTED;
        }

        if (! is_scalar($value)) {
            return self::REDACTED;
        }

        $text = (string) $value;

        // Too short to mask meaningfully: revealing "ab" of a 3-character value
        // is revealing most of it.
        if (mb_strlen($text) <= self::VISIBLE_SUFFIX) {
            return self::REDACTED;
        }

        return str_repeat('*', mb_strlen($text) - self::VISIBLE_SUFFIX)
            .mb_substr($text, -self::VISIBLE_SUFFIX);
    }

    /**
     * Objects can carry anything, including a whole Eloquent model with
     * attributes nobody intended to store. Only plain values pass.
     */
    private function scalar(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        return self::REDACTED;
    }
}
