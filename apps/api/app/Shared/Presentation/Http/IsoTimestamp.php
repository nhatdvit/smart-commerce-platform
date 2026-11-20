<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Http;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * One way to put a timestamp on the wire.
 *
 * Every API timestamp is UTC in Zulu form - `2026-11-20T07:15:00Z` - which is
 * what the error envelope already emits and what the designed contract's
 * `date-time` fields mean. Shared rather than written per resource, because
 * two modules formatting dates differently is the kind of inconsistency that
 * only shows up in a client, months later.
 *
 * Timestamps are stored in UTC (`TIMESTAMPTZ`) and converted to store-local
 * time only where a business rule is evaluated in it - opening hours, menu
 * schedules, daily quotas (CLAUDE.md section 6). A response carries the
 * absolute instant and lets the client render it.
 */
final readonly class IsoTimestamp
{
    public static function of(?DateTimeInterface $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
