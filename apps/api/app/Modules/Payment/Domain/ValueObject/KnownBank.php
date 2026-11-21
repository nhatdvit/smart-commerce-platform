<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObject;

/**
 * The NAPAS/VietQR bank identifier directory.
 *
 * A typo in a bank identifier does not fail loudly - it sends a customer's
 * money toward a bank the operator never meant to choose - so the designed
 * contract validates `bank_bin` against "the known VietQR BIN list" rather
 * than accepting any six digits, and resolves the display name from it rather
 * than trusting whatever an operator typed next to it.
 *
 * **This list needs upkeep.** It covers the major, long-established
 * participants in NAPAS's VietQR network and is reasonably stable for them,
 * but NAPAS adds participants over time and this file has no way to notice.
 * Treat an unrecognised BIN from a legitimate bank as a sign this list is
 * stale, not as proof the BIN is wrong - add it here rather than bypassing the
 * check. It is reference data, not environment configuration, which is why it
 * lives here rather than in `config/` (CLAUDE.md section 16 keeps domain
 * knowledge out of config; a BIN is a fact about Vietnam's banking system, not
 * a fact about this deployment).
 *
 * The keys are written as integers, not quoted digit strings. PHP casts an
 * all-digit string array key to an integer the moment it is used as one -
 * `'970436' => ...` and `970436 => ...` produce the identical array - so
 * writing the quotes would only have claimed a type the language was always
 * going to override; `nameFor()` casts its input the same way, which is what
 * makes the two sides agree.
 */
final readonly class KnownBank
{
    /** @var array<int, string> BIN => display name */
    private const array DIRECTORY = [
        970436 => 'Vietcombank',
        970415 => 'VietinBank',
        970418 => 'BIDV',
        970405 => 'Agribank',
        970407 => 'Techcombank',
        970422 => 'MB Bank',
        970416 => 'ACB',
        970432 => 'VPBank',
        970423 => 'TPBank',
        970403 => 'Sacombank',
        970437 => 'HDBank',
        970443 => 'SHB',
        970441 => 'VIB',
        970448 => 'OCB',
        970426 => 'MSB',
        970440 => 'SeABank',
        970431 => 'Eximbank',
        970449 => 'LPBank',
        970428 => 'Nam A Bank',
        970409 => 'BacABank',
        970412 => 'PVcomBank',
        970429 => 'SCB',
        970406 => 'DongA Bank',
        970419 => 'NCB',
        970425 => 'ABBANK',
        970427 => 'VietABank',
        970438 => 'Bao Viet Bank',
        970430 => 'PGBank',
    ];

    public static function isKnown(string $bin): bool
    {
        return ctype_digit($bin) && array_key_exists((int) $bin, self::DIRECTORY);
    }

    public static function nameFor(string $bin): ?string
    {
        return ctype_digit($bin) ? (self::DIRECTORY[(int) $bin] ?? null) : null;
    }

    /**
     * The whole directory, for a picker rather than a free-typed field - an
     * operator choosing a bank by name cannot mistype a BIN that happens to
     * belong to a different, real bank (CLAUDE.md section 6).
     *
     * @return list<array{bin: string, name: string}>
     */
    public static function all(): array
    {
        $entries = array_map(
            static fn (int $bin, string $name): array => ['bin' => (string) $bin, 'name' => $name],
            array_keys(self::DIRECTORY),
            array_values(self::DIRECTORY),
        );

        usort($entries, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $entries;
    }
}
