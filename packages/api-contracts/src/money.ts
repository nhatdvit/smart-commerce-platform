/**
 * Monetary amounts are whole Vietnamese đồng, never floating point.
 *
 * Storage:   PostgreSQL BIGINT    (Eloquent `bigInteger`, PHP `int`)
 * Transport: decimal string      (JSON has no safe 64-bit integer)
 *
 * This is the client-side half of the contract. The server-side counterpart is
 * `App\Shared\Domain\ValueObject\Money` in the Laravel API, which does the
 * arithmetic; the browser only ever formats and displays these values.
 *
 * See CLAUDE.md §6 "Monetary arithmetic must be exact".
 */
export type CurrencyCode = 'VND';

/** Whole đồng as an exact integer. Use only inside the server runtime. */
export type MinorAmount = bigint;

/** Whole đồng serialised for transport, e.g. "45000". */
export type MoneyString = string;

export interface Money {
  readonly amount: MoneyString;
  readonly currency: CurrencyCode;
}

export const VND: CurrencyCode = 'VND';

/** VND has no sub-unit, so the minor unit is the đồng itself. */
export const CURRENCY_MINOR_UNITS: Record<CurrencyCode, number> = { VND: 0 };

export function toMoney(amount: MinorAmount, currency: CurrencyCode = VND): Money {
  return { amount: amount.toString(), currency };
}

export function fromMoney(money: Money): MinorAmount {
  return BigInt(money.amount);
}
