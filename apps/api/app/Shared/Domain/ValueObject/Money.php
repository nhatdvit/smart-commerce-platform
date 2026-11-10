<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use InvalidArgumentException;
use JsonSerializable;

/**
 * An exact monetary amount in whole Vietnamese dong.
 *
 * VND has no sub-unit, so the amount is a plain integer and arithmetic is
 * exact. Floats are never used for money (CLAUDE.md section 6). PHP integers are
 * 64-bit on every platform this runs on, which is far beyond any realistic
 * order total.
 *
 * Storage:   BIGINT
 * Transport: decimal string, because JSON numbers cannot hold a 64-bit
 *            integer safely in every client.
 */
final readonly class Money implements JsonSerializable
{
    public const string CURRENCY_VND = 'VND';

    private function __construct(
        public int $amount,
        public string $currency,
    ) {}

    public static function vnd(int $amount): self
    {
        return new self($amount, self::CURRENCY_VND);
    }

    public static function zero(): self
    {
        return new self(0, self::CURRENCY_VND);
    }

    /** Parse the transport representation, e.g. "45000". */
    public static function fromString(string $amount, string $currency = self::CURRENCY_VND): self
    {
        if (preg_match('/^-?\d+$/', $amount) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Monetary amount must be whole dong, got "%s"', $amount),
            );
        }

        return new self((int) $amount, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    /** Multiply by a whole quantity; a line total is never fractional. */
    public function times(int $quantity): self
    {
        if ($quantity < 0) {
            throw new InvalidArgumentException('Quantity cannot be negative');
        }

        return new self($this->amount * $quantity, $this->currency);
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    public function __toString(): string
    {
        return (string) $this->amount;
    }

    /** @return array{amount: string, currency: string} */
    public function jsonSerialize(): array
    {
        return ['amount' => (string) $this->amount, 'currency' => $this->currency];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                sprintf('Cannot combine %s with %s', $this->currency, $other->currency),
            );
        }
    }
}
