<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTO;

/**
 * A stored file, as housekeeping sees it.
 *
 * The store is carried alongside rather than parsed back out of the URL: the
 * URL is opaque by contract, and a reader that took it apart would break the
 * day uploads move to a bucket.
 */
final readonly class StoredImageRef
{
    public function __construct(
        public string $storeId,
        public string $imageUrl,
    ) {}
}
