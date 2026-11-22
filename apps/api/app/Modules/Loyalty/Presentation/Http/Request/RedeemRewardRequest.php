<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/** Spends a member's points on a reward, recorded against the staff member who did it. */
final class RedeemRewardRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reward_id' => ['required', 'uuid'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reward_id.required' => 'Vui lòng chọn quà tặng.',
        ];
    }

    public function rewardId(): string
    {
        /** @var string $id */
        $id = $this->validated()['reward_id'];

        return $id;
    }

    public function note(): ?string
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();
        $note = $data['note'] ?? null;

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
