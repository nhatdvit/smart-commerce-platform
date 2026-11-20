<?php

declare(strict_types=1);

namespace App\Modules\Menu\Presentation\Http\Request;

use App\Modules\Menu\Application\DTO\DailyPortionsInput;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the morning prep sheet.
 *
 * One line per dish, and the same dish twice is refused rather than resolved:
 * a sheet that says both forty and sixty bánh mì has no correct answer, and
 * picking the last one silently would be a guess about someone's morning.
 */
final class SetDailyPortionsRequest extends FormRequest
{
    /** Matches MAX_PORTIONS in the admin form. */
    public const int MAX_PORTIONS = 10_000;

    /** A menu holds far fewer dishes than this; the cap is a payload bound. */
    public const int MAX_LINES = 500;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'items.*.id' => ['required', 'uuid', 'distinct'],
            'items.*.prepared_quantity' => ['required', 'integer', 'min:0', 'max:'.self::MAX_PORTIONS],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.required' => 'Chưa có món nào để cập nhật số suất.',
            'items.min' => 'Chưa có món nào để cập nhật số suất.',
            'items.*.id.distinct' => 'Mỗi món chỉ được ghi một lần trong bảng chuẩn bị.',
            'items.*.prepared_quantity.integer' => 'Số suất chuẩn bị phải là số nguyên, không âm.',
            'items.*.prepared_quantity.min' => 'Số suất chuẩn bị phải là số nguyên, không âm.',
            'items.*.prepared_quantity.max' => 'Số suất chuẩn bị tối đa '.self::MAX_PORTIONS.'.',
        ];
    }

    /**
     * @return list<DailyPortionsInput>
     */
    public function toInput(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = array_values((array) ($this->validated()['items'] ?? []));

        return array_values(array_map(
            static fn (array $row): DailyPortionsInput => new DailyPortionsInput(
                menuItemId: (string) $row['id'],
                preparedQuantity: (int) $row['prepared_quantity'],
            ),
            $rows,
        ));
    }
}
