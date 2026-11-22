<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\DTO;

final readonly class RewardInput
{
    public function __construct(
        public string $name,
        public int $pointsCost,
        public ?string $icon,
        public bool $isActive,
    ) {}
}
