<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/** The optional `{reason}` body a customer may send when cancelling their own order. */
final class CancelOrderRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function reason(): ?string
    {
        $value = $this->validated()['reason'] ?? null;

        return is_string($value) ? $value : null;
    }
}
