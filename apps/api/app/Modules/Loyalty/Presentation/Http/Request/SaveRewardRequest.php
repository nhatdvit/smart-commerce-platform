<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Http\Request;

use App\Modules\Loyalty\Application\DTO\RewardInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a reward, for both create and edit.
 *
 * Shape only, same split `SaveProductRequest` draws for its own icon field:
 * the actual icon catalogue is the admin UI's concern, not a list this
 * endpoint has to keep in sync with it.
 */
final class SaveRewardRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'points_cost' => ['required', 'integer', 'min:1', 'max:1000000'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Vui lòng nhập tên quà tặng.',
            'points_cost.required' => 'Vui lòng nhập số điểm đổi.',
            'points_cost.min' => 'Số điểm đổi phải lớn hơn 0.',
        ];
    }

    public function toInput(): RewardInput
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return new RewardInput(
            name: (string) $data['name'],
            pointsCost: (int) $data['points_cost'],
            icon: isset($data['icon']) && is_string($data['icon']) ? $data['icon'] : null,
            isActive: (bool) ($data['is_active'] ?? true),
        );
    }
}
