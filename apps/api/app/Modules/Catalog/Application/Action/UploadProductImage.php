<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Action;

use App\Modules\Catalog\Application\DTO\StoredImage;
use App\Modules\Catalog\Domain\Contract\ProductImageStorage;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use finfo;

/**
 * Accepts a product photo and stores it.
 *
 * The type is decided from the BYTES, not from the filename or the type the
 * client declared, because both are attacker-controlled: a file called
 * `.png` whose contents are a script would otherwise be stored and later
 * served from the dashboard's own origin.
 *
 * No transaction and no database row: an upload writes one file and nothing
 * else. A photo nobody saves on a product is just an unreferenced file, which
 * is cheaper than making the form create a product it may never keep.
 */
final readonly class UploadProductImage
{
    public function __construct(
        private ProductImageStorage $storage,
        private RecordsAuditTrail $audit,
    ) {}

    /**
     * @param  string  $contents  raw uploaded bytes
     * @param  list<string>  $allowedTypes  accepted image content types
     */
    public function handle(string $storeId, string $contents, array $allowedTypes, int $maxBytes): StoredImage
    {
        if ($contents === '') {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'The uploaded file is empty',
                [['field' => 'file', 'message' => 'Tệp ảnh rỗng.']],
            );
        }

        if (strlen($contents) > $maxBytes) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'The uploaded file is too large',
                [['field' => 'file', 'message' => 'Ảnh vượt quá dung lượng cho phép.']],
            );
        }

        $contentType = $this->sniff($contents);

        if ($contentType === null || ! in_array($contentType, $allowedTypes, true)) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'Only JPEG, PNG and WebP images are accepted',
                [['field' => 'file', 'message' => 'Chỉ nhận ảnh JPEG, PNG hoặc WebP.']],
            );
        }

        $stored = $this->storage->put($storeId, $contents, $contentType);

        $this->audit->record(
            // The identity is the stored file name, not the whole URL: the URL
            // is a location that can change with the storage backend, and the
            // audit column holds an identifier.
            AuditEntry::for('catalog.product-image.upload', 'product_image', basename($stored->imageUrl), [
                'image_url' => $stored->imageUrl,
                'content_type' => $stored->contentType,
                'byte_size' => $stored->byteSize,
            ])->inStore($storeId),
        );

        return $stored;
    }

    /**
     * Reads the type out of the bytes themselves.
     *
     * finfo is the only image-aware extension this image carries - there is no
     * GD and no Imagick - so the file is identified rather than re-encoded.
     * That is why the serving route pins the content type and sends `nosniff`:
     * identification alone cannot prove a file is nothing but an image.
     */
    private function sniff(string $contents): ?string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($contents);

        return is_string($detected) && $detected !== '' ? $detected : null;
    }
}
