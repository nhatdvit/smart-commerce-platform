<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Entity;

use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;

/**
 * One thing a member can redeem points for - usually a menu item given away
 * free, which is why it carries the same icon catalogue the menu screen uses
 * rather than an uploaded image.
 */
final readonly class Reward
{
    /**
     * @param  int|null  $redeemedCount  How many times this has ever been redeemed.
     *                                   Null on a freshly created reward that has not been read back with history.
     */
    private function __construct(
        public ?string $id,
        public string $storeId,
        public string $name,
        public int $pointsCost,
        public ?string $icon,
        public bool $isActive,
        public ?int $redeemedCount = null,
    ) {}

    public static function create(
        ?string $id,
        string $storeId,
        string $name,
        int $pointsCost,
        ?string $icon,
        bool $isActive = true,
        ?int $redeemedCount = null,
    ): self {
        $name = trim($name);

        if ($name === '') {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A reward needs a name',
                [['field' => 'name', 'message' => 'Vui lòng nhập tên quà tặng.']],
            );
        }

        if ($pointsCost <= 0) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A reward must cost at least one point',
                [['field' => 'points_cost', 'message' => 'Số điểm đổi phải lớn hơn 0.']],
            );
        }

        return new self($id, $storeId, $name, $pointsCost, $icon, $isActive, $redeemedCount);
    }
}
