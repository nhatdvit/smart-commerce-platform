<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Http\Request;

use App\Modules\Order\Domain\Enum\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `TransitionOrderRequest`: where this order is going, and why.
 *
 * The target status is explicit rather than a "next" command. Two staff
 * members tapping at once would both mean something different by "next", and
 * the second tap would move the order one step further than anyone intended.
 * Sending the status the operator saw turns that race into a 409 they can see
 * (CLAUDE.md section 6).
 *
 * Whether the move is legal at all is the state machine's answer, not this
 * one. A Form Request can only say the field is a known status.
 */
final class TransitionOrderRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to_status' => [
                'required',
                'string',
                Rule::in(array_column(OrderStatus::cases(), 'value')),
            ],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to_status.required' => 'Vui lòng chọn trạng thái muốn chuyển sang.',
            'to_status.in' => 'Trạng thái đơn hàng không hợp lệ.',
            'reason.max' => 'Lý do không được dài hơn 255 ký tự.',
        ];
    }

    public function toStatus(): OrderStatus
    {
        return OrderStatus::from((string) $this->validated()['to_status']);
    }

    /**
     * The reason, when one was given.
     *
     * Required for a cancellation, which the use case enforces: the rule
     * belongs with the transition, where the expiry job and a future kitchen
     * display are held to it too.
     */
    public function reason(): ?string
    {
        $value = $this->validated()['reason'] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
