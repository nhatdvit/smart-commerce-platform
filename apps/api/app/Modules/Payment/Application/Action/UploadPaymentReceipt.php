<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Action;

use App\Modules\Payment\Domain\Contract\PaymentReceiptStorage;
use App\Shared\Application\Audit\AuditEntry;
use App\Shared\Application\Contract\RecordsAuditTrail;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use finfo;

/**
 * Accepts a customer's receipt photo and stores it against one payment.
 *
 * Mirrors Catalog's `UploadProductImage` deliberately rather than reusing it:
 * the type is still decided from the BYTES, never the filename or the
 * client-declared type, both attacker-controlled - but this is a different
 * module's storage, audited under a different action name, and this upload
 * is never public the way a product photo is (CLAUDE.md section 8).
 */
final readonly class UploadPaymentReceipt
{
    public function __construct(
        private PaymentReceiptStorage $storage,
        private RecordsAuditTrail $audit,
    ) {}

    /**
     * @param  string  $contents  raw uploaded bytes
     * @param  list<string>  $allowedTypes  accepted image content types
     * @return string the stored path, to pass into ReportCustomerTransfer
     */
    public function handle(
        string $storeId,
        string $paymentId,
        string $contents,
        array $allowedTypes,
        int $maxBytes,
    ): string {
        if ($contents === '') {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'The uploaded file is empty',
                [['field' => 'receipt', 'message' => 'Tệp ảnh rỗng.']],
            );
        }

        if (strlen($contents) > $maxBytes) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'The uploaded file is too large',
                [['field' => 'receipt', 'message' => 'Ảnh vượt quá dung lượng cho phép.']],
            );
        }

        $contentType = $this->sniff($contents);

        if ($contentType === null || ! in_array($contentType, $allowedTypes, true)) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'Only JPEG, PNG and WebP images are accepted',
                [['field' => 'receipt', 'message' => 'Chỉ nhận ảnh JPEG, PNG hoặc WebP.']],
            );
        }

        $path = $this->storage->put($storeId, $paymentId, $contents, $contentType);

        $this->audit->record(
            AuditEntry::for('payment.receipt.upload', 'payment', $paymentId, [
                'content_type' => $contentType,
                'byte_size' => strlen($contents),
            ])->inStore($storeId),
        );

        return $path;
    }

    /** Reads the type out of the bytes themselves; see UploadProductImage's own note. */
    private function sniff(string $contents): ?string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->buffer($contents);

        return is_string($detected) && $detected !== '' ? $detected : null;
    }
}
