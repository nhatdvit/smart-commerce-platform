<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Enum;

enum MembershipStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';

    public function grantsAccess(): bool
    {
        return $this === self::Active;
    }
}
