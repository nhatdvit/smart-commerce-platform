<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Validates one customer "I have transferred" report.
 *
 * Lives in Order, not Payment, even though what it produces flows into
 * Payment's `UploadPaymentReceipt`/`ReportCustomerTransfer` actions: a
 * Presentation-layer class belongs to the module whose controller uses it,
 * and `CustomerOrderController` may only reach Payment through its exported
 * Application layer (`ArchitectureTest`: "the order module reaches payments
 * only through their exported contract").
 *
 * The receipt is optional - a bare click is still a real, useful
 * notification - and these rules are only the cheap first pass on what the
 * client declared; `UploadPaymentReceipt` decides the real type from the bytes.
 */
final class ReportCustomerTransferRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxKilobytes = (int) (((int) config('commerce.media.max_bytes', 2 * 1024 * 1024)) / 1024);

        return [
            'receipt' => ['sometimes', 'nullable', 'file', 'max:'.$maxKilobytes],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'receipt.max' => 'Ảnh vượt quá dung lượng cho phép.',
            'receipt.uploaded' => 'Ảnh vượt quá dung lượng cho phép.',
        ];
    }

    public function hasReceipt(): bool
    {
        return $this->file('receipt') instanceof UploadedFile;
    }

    /** The raw bytes, or '' when no file was sent. */
    public function receiptContents(): string
    {
        $file = $this->file('receipt');

        return $file instanceof UploadedFile ? (string) file_get_contents($file->getRealPath()) : '';
    }
}
