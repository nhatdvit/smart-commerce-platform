<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Enum;

enum UserStatus: string
{
    case Active = 'ACTIVE';
    case Blocked = 'BLOCKED';

    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }
}
