<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTO;

use App\Modules\Catalog\Domain\Enum\CatalogStatus;
use App\Shared\Domain\ValueObject\Money;

/** One variant as the caller asked for it, already parsed out of transport types. */
final readonly class VariantInput
{
    public function __construct(
        /** Present when the caller is updating an existing variant. */
        public ?string $id,
        public ?string $sku,
        public string $name,
        public Money $basePrice,
        public CatalogStatus $status,
        public int $sortOrder = 0,
    ) {}
}
