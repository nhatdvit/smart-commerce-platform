<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Storage;

use App\Modules\Payment\Domain\Contract\PaymentReceiptStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

/**
 * Keeps payment receipts on a Laravel disk, one file per payment.
 *
 * Named after the PAYMENT, not a random id: a payment has at most one current
 * receipt, so there is nothing to enumerate and nothing to look up by id -
 * `put()` simply overwrites whatever was there before. Mirrors
 * `DiskProductImageStorage`'s extension-from-content-type rule for the same
 * reason: the name is never built from anything the client sent.
 */
final readonly class DiskPaymentReceiptStorage implements PaymentReceiptStorage
{
    private const string ROOT = 'payment-receipts';

    /** Content type to extension. The only extensions this class can produce. */
    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private Filesystem $disk) {}

    public function put(string $storeId, string $paymentId, string $contents, string $contentType): string
    {
        $extension = self::EXTENSIONS[$contentType] ?? null;

        if ($extension === null) {
            // Unreachable through the endpoint, which validates first (see
            // UploadPaymentReceipt). Kept as a hard stop so a future caller
            // cannot write an arbitrary type.
            throw new RuntimeException(sprintf('Unsupported image type %s', $contentType));
        }

        // A prior receipt under a different extension would otherwise be
        // left behind, orphaned once the row's path points elsewhere.
        foreach (self::EXTENSIONS as $staleExtension) {
            $stale = $this->path($storeId, $paymentId, $staleExtension);

            if ($stale !== $this->path($storeId, $paymentId, $extension) && $this->disk->exists($stale)) {
                $this->disk->delete($stale);
            }
        }

        $path = $this->path($storeId, $paymentId, $extension);
        $this->disk->put($path, $contents);

        return $path;
    }

    public function read(string $path): ?array
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $contentType = array_search($extension, self::EXTENSIONS, true);

        if ($contentType === false || ! $this->disk->exists($path)) {
            return null;
        }

        $contents = $this->disk->get($path);

        return $contents === null ? null : ['contents' => $contents, 'content_type' => $contentType];
    }

    public function delete(string $path): bool
    {
        return $this->disk->exists($path) && $this->disk->delete($path);
    }

    private function path(string $storeId, string $paymentId, string $extension): string
    {
        return sprintf('%s/%s/%s.%s', self::ROOT, $storeId, $paymentId, $extension);
    }
}
