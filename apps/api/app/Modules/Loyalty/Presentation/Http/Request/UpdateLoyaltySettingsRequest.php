<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/** Changes how many points a PAID order earns, and the loyal-tier threshold. */
final class UpdateLoyaltySettingsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'points_per_currency' => ['required', 'integer', 'min:1', 'max:10000000'],
            'loyal_tier_threshold_points' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'points_per_currency.required' => 'Vui lòng nhập mức quy đổi điểm.',
            'points_per_currency.min' => 'Mức quy đổi phải lớn hơn 0.',
            'loyal_tier_threshold_points.min' => 'Ngưỡng hạng thân thiết không được âm.',
        ];
    }

    public function pointsPerCurrency(): int
    {
        return (int) $this->validated()['points_per_currency'];
    }

    public function loyalTierThresholdPoints(): int
    {
        return (int) $this->validated()['loyal_tier_threshold_points'];
    }
}
