<?php

declare(strict_types=1);

namespace App\Modules\Menu\Presentation\Http\Request;

use App\Modules\Menu\Application\DTO\MenuInput;
use App\Modules\Menu\Application\DTO\MenuItemInput;
use App\Modules\Menu\Domain\Enum\MenuStatus;
use App\Shared\Domain\ValueObject\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `UpdateMenuRequest`: a full replacement of a menu.
 *
 * The portion limits match the admin form's
 * (`apps/admin-web/src/lib/product-form.ts`), and the price rule is the same
 * digits-only string rule the catalog uses - one definition of "a price", not
 * two that can drift (CLAUDE.md section 6).
 */
final class ReplaceMenuRequest extends FormRequest
{
    /** Matches MAX_PRICE in the admin form. */
    public const int MAX_PRICE_AMOUNT = 100_000_000;

    /** Matches MAX_PORTIONS in the admin form. */
    public const int MAX_PORTIONS = 10_000;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'status' => ['required', 'string', Rule::in(array_column(MenuStatus::cases(), 'value'))],

            'items' => ['present', 'array', 'max:500'],
            'items.*.id' => ['sometimes', 'nullable', 'uuid'],
            'items.*.variant_id' => ['required', 'uuid'],
            'items.*.selling_price_amount' => [
                'required',
                'string',
                'regex:/^[0-9]+$/',
                'numeric',
                'max:'.self::MAX_PRICE_AMOUNT,
            ],
            'items.*.is_available' => ['sometimes', 'boolean'],
            'items.*.available_quantity' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.self::MAX_PORTIONS],
            'items.*.daily_capacity' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.self::MAX_PORTIONS],
            'items.*.sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            // Designed, not built. Silently dropping overrides would leave the
            // caller believing a price it never saved.
            'items.*.modifier_price_overrides' => ['prohibited'],

            // Schedules and date exceptions are designed but not stored yet.
            // Accepting them would promise a menu window that nothing enforces.
            'schedules' => ['present', 'array', 'max:0'],
            'exceptions' => ['sometimes', 'array', 'max:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.*.selling_price_amount.regex' => 'Giá bán phải là số nguyên đồng, không âm.',
            'schedules.max' => 'Lịch thực đơn theo giờ chưa được hỗ trợ trong giai đoạn này.',
            'exceptions.max' => 'Ngoại lệ theo ngày chưa được hỗ trợ trong giai đoạn này.',
            'items.*.modifier_price_overrides.prohibited' => 'Tuỳ chọn món (modifier) chưa được hỗ trợ trong giai đoạn này.',
        ];
    }

    public function toInput(): MenuInput
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return new MenuInput(
            name: (string) $data['name'],
            status: MenuStatus::from((string) $data['status']),
            items: $this->items($data['items'] ?? []),
        );
    }

    /**
     * @return list<MenuItemInput>
     */
    private function items(mixed $items): array
    {
        $parsed = [];
        /** @var array<int, array<string, mixed>> $rows */
        $rows = array_values((array) $items);

        foreach ($rows as $index => $item) {
            $parsed[] = new MenuItemInput(
                variantId: (string) $item['variant_id'],
                sellingPrice: Money::fromString((string) $item['selling_price_amount']),
                isAvailable: (bool) ($item['is_available'] ?? true),
                availableQuantity: $this->nullableInt($item['available_quantity'] ?? null),
                dailyCapacity: $this->nullableInt($item['daily_capacity'] ?? null),
                sortOrder: isset($item['sort_order']) ? (int) $item['sort_order'] : $index,
                id: isset($item['id']) && is_string($item['id']) ? $item['id'] : null,
                quantityGiven: array_key_exists('available_quantity', $item),
                capacityGiven: array_key_exists('daily_capacity', $item),
            );
        }

        return $parsed;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
