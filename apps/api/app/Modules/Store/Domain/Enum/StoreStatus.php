<?php

declare(strict_types=1);

namespace App\Modules\Store\Domain\Enum;

enum StoreStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';

    public function canAcceptOrders(): bool
    {
        return $this === self::Active;
    }
}
