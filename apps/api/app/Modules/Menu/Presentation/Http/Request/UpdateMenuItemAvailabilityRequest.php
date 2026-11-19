<?php

declare(strict_types=1);

namespace App\Modules\Menu\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `UpdateMenuItemAvailabilityRequest`.
 *
 * The sold-out switch is required; the portion counts are optional, and
 * "omitted" is kept distinct from "set to null" so flipping the switch cannot
 * wipe the kitchen's numbers.
 */
final class UpdateMenuItemAvailabilityRequest extends FormRequest
{
    /** Matches MAX_PORTIONS in the admin form. */
    public const int MAX_PORTIONS = 10_000;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'is_available' => ['required', 'boolean'],
            'available_quantity' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.self::MAX_PORTIONS],
            'daily_capacity' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.self::MAX_PORTIONS],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'available_quantity.integer' => 'Số suất còn lại phải là số nguyên, không âm.',
            'available_quantity.min' => 'Số suất còn lại phải là số nguyên, không âm.',
            'daily_capacity.integer' => 'Số suất chuẩn bị phải là số nguyên, không âm.',
            'daily_capacity.min' => 'Số suất chuẩn bị phải là số nguyên, không âm.',
        ];
    }

    public function isAvailable(): bool
    {
        return (bool) $this->validated()['is_available'];
    }

    public function quantityGiven(): bool
    {
        return array_key_exists('available_quantity', $this->validated());
    }

    public function capacityGiven(): bool
    {
        return array_key_exists('daily_capacity', $this->validated());
    }

    public function availableQuantity(): ?int
    {
        $value = $this->validated()['available_quantity'] ?? null;

        return is_int($value) ? $value : null;
    }

    public function dailyCapacity(): ?int
    {
        $value = $this->validated()['daily_capacity'] ?? null;

        return is_int($value) ? $value : null;
    }
}
