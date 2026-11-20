<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Validates one uploaded product photo.
 *
 * These rules are the cheap first pass. They read what the client declared, so
 * the authoritative check is the content sniff in UploadProductImage, which
 * reads the bytes: a rule on the declared type can be satisfied by renaming a
 * file.
 */
final class UploadProductImageRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxKilobytes = (int) (((int) config('commerce.media.max_bytes', 2 * 1024 * 1024)) / 1024);

        return [
            'file' => ['required', 'file', 'max:'.$maxKilobytes],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'Vui lòng chọn một tệp ảnh.',
            'file.max' => 'Ảnh vượt quá dung lượng cho phép.',
            'file.uploaded' => 'Ảnh vượt quá dung lượng cho phép.',
        ];
    }

    /** The raw bytes, which is all the use case needs. */
    public function contents(): string
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? (string) file_get_contents($file->getRealPath()) : '';
    }
}
