<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Contract;

use App\Modules\Catalog\Application\DTO\StoredImage;

/**
 * Where product photography is kept.
 *
 * A seam rather than a direct call to a disk, because the destination is a
 * deployment decision: the API's own disk today, object storage once it is
 * configured (CLAUDE.md §2 and §9). Everything above this interface is written
 * once and does not care which it is.
 */
interface ProductImageStorage
{
    /**
     * Stores image bytes for one store and says where they ended up.
     *
     * @param  string  $contents  the raw bytes, already validated as an image
     * @param  string  $contentType  one of the accepted image types
     */
    public function put(string $storeId, string $contents, string $contentType): StoredImage;

    /**
     * The bytes behind a stored file, or null when there is no such file.
     *
     * @return array{contents: string, content_type: string}|null
     */
    public function read(string $storeId, string $fileName): ?array;
}
