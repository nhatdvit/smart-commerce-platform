<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

/**
 * A human-entered name, and the one way two of them are compared.
 *
 * Shared rather than owned by a module because products, categories and menus
 * all need the same answer to "is this the same name?", and two modules
 * answering it differently would be a bug nobody could see.
 *
 * Both halves live together because they have to agree: the stored key IS the
 * comparison, so a name can never pass an application check and then collide
 * in the database, or the reverse.
 *
 * Why a key at all, rather than `lower(name)` in SQL: PostgreSQL's `lower()`
 * folds case according to the database collation, and on a C-collation cluster
 * - which is what the Alpine image provides - it leaves every non-ASCII letter
 * alone. `lower('BÁNH MÌ')` comes back as `bÁnh mÌ`, so a uniqueness rule built
 * on it would read two spellings of one Vietnamese name as two dishes. PHP's
 * mb_strtolower folds Unicode properly and matches the admin form's
 * `toLocaleLowerCase('vi')`, so the rule is the same on both sides of the wire.
 */
final readonly class DisplayName
{
    /** Collapses the whitespace a pasted name usually carries. */
    public static function normalise(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /** The comparison key: tidied, then case-folded across the whole alphabet. */
    public static function key(string $name): string
    {
        return mb_strtolower(self::normalise($name), 'UTF-8');
    }
}
