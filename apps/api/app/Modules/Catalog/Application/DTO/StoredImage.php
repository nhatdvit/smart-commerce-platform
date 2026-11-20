<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTO;

/** An uploaded photo, as stored. */
final readonly class StoredImage
{
    public function __construct(
        /**
         * What a client saves on the product and sends back unchanged.
         *
         * Opaque on purpose: a path on this API while uploads live on its own
         * disk, a bucket URL once object storage is configured. A client that
         * built one itself would break on that switch.
         */
        public string $imageUrl,
        public string $contentType,
        public int $byteSize,
    ) {}
}
