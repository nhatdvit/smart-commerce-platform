<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Enum;

/**
 * What a ledger row records. The sign of its `points` column always agrees
 * with this - see the migration note on why a balance is a plain SUM.
 */
enum EntryType: string
{
    case Earn = 'EARN';
    case Redeem = 'REDEEM';
}
