<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Storage;

use App\Modules\Catalog\Application\DTO\StoredImage;
use App\Modules\Catalog\Domain\Contract\ProductImageStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Keeps product photos on a Laravel disk.
 *
 * The disk is configuration (`commerce.media.disk`), so the same class serves
 * the API's own storage today and an S3 bucket the day one is configured.
 *
 * Two rules make the path safe to echo back in a URL:
 *
 *   - the name is generated here, never taken from the upload. A client
 *     filename is attacker-controlled and would carry its own extension,
 *     its own path separators and whatever the host filesystem makes of
 *     them;
 *   - the extension comes from the content type the bytes were accepted as,
 *     from a fixed map, so the name cannot end in anything executable.
 *
 * Files are grouped per store, which keeps "whose photo is this" answerable
 * from the path alone.
 */
final readonly class DiskProductImageStorage implements ProductImageStorage
{
    /** Content type to extension. The only extensions this class can produce. */
    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private Filesystem $disk) {}

    public function put(string $storeId, string $contents, string $contentType): StoredImage
    {
        $extension = self::EXTENSIONS[$contentType] ?? null;

        if ($extension === null) {
            // Unreachable through the endpoint, which validates first. Kept as
            // a hard stop so a future caller cannot write an arbitrary type.
            throw new RuntimeException(sprintf('Unsupported image type %s', $contentType));
        }

        $fileName = Str::uuid7().'.'.$extension;

        $this->disk->put($this->path($storeId, $fileName), $contents);

        return new StoredImage(
            imageUrl: sprintf('/api/v1/media/products/%s/%s', $storeId, $fileName),
            contentType: $contentType,
            byteSize: strlen($contents),
        );
    }

    public function read(string $storeId, string $fileName): ?array
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $contentType = array_search($extension, self::EXTENSIONS, true);

        // The route already constrains the name, but the content type is
        // decided here from the extension rather than sniffed from the bytes:
        // a file is served as the type it was accepted as, or not at all.
        if ($contentType === false) {
            return null;
        }

        $path = $this->path($storeId, $fileName);

        if (! $this->disk->exists($path)) {
            return null;
        }

        $contents = $this->disk->get($path);

        return $contents === null ? null : ['contents' => $contents, 'content_type' => $contentType];
    }

    private function path(string $storeId, string $fileName): string
    {
        return sprintf('media/products/%s/%s', $storeId, $fileName);
    }
}
