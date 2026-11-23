<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Contract;

/**
 * Where a customer's "I have transferred" receipt photo lives.
 *
 * Deliberately separate from Catalog's `ProductImageStorage`: a product photo
 * is public, served to anyone browsing the storefront; a payment receipt is
 * sensitive and served only to staff authorized to view that payment
 * (CLAUDE.md section 8). One payment keeps at most one receipt - a re-upload
 * replaces it, there is no gallery.
 */
interface PaymentReceiptStorage
{
    /** @return string the stored path, to persist on the payment row */
    public function put(string $storeId, string $paymentId, string $contents, string $contentType): string;

    /** @return array{contents: string, content_type: string}|null null when the path is stale or missing */
    public function read(string $path): ?array;

    public function delete(string $path): bool;
}
