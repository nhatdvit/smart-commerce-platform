<?php

declare(strict_types=1);

namespace App\Modules\Order\Presentation\Http\Request;

use App\Modules\Order\Application\DTO\CreateOrderInput;
use App\Modules\Order\Application\DTO\CreateOrderLineInput;
use App\Modules\Order\Domain\Enum\FulfillmentType;
use App\Modules\Payment\Domain\Enum\PaymentMethod;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the implemented `CreateOrderRequest`: line items given directly
 * rather than a `cart_id` - see {@see \App\Modules\Order\Application\Action\CreateOrder}
 * for why there is no Cart to reference yet.
 */
final class CreateOrderRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fulfillment_type' => ['required', 'string', Rule::in(['PICKUP', 'DELIVERY'])],
            'payment_method' => ['required', 'string', Rule::in(['CASH', 'BANK_TRANSFER'])],
            'contact' => ['required', 'array'],
            'contact.name' => ['required', 'string', 'max:150'],
            'contact.phone' => ['required', 'string', 'max:20'],
            'contact.email' => ['nullable', 'email', 'max:254'],
            'delivery_address_line' => [
                'nullable',
                'string',
                'max:255',
                Rule::requiredIf(fn (): bool => $this->input('fulfillment_type') === 'DELIVERY'),
            ],
            'scheduled_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.menu_item_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
            // A decimal string, matching Money's own transport format - never
            // an input to pricing, only a consent check against it.
            'expected_total_amount' => ['nullable', 'regex:/^\d+$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'fulfillment_type.required' => 'Vui lòng chọn hình thức nhận hàng.',
            'payment_method.required' => 'Vui lòng chọn phương thức thanh toán.',
            'contact.name.required' => 'Vui lòng nhập tên người nhận.',
            'contact.phone.required' => 'Vui lòng nhập số điện thoại.',
            'delivery_address_line.required' => 'Đơn giao hàng phải có địa chỉ giao.',
            'items.required' => 'Giỏ hàng đang trống.',
            'items.min' => 'Giỏ hàng đang trống.',
            'items.*.menu_item_id.required' => 'Thiếu món trong giỏ hàng.',
            'items.*.menu_item_id.uuid' => 'Món trong giỏ hàng không hợp lệ.',
            'items.*.quantity.min' => 'Số lượng phải từ 1 trở lên.',
        ];
    }

    public function toInput(): CreateOrderInput
    {
        $validated = $this->validated();

        /** @var list<array{menu_item_id: string, quantity: int, note?: string|null}> $items */
        $items = $validated['items'];

        return new CreateOrderInput(
            fulfillmentType: FulfillmentType::from((string) $validated['fulfillment_type']),
            paymentMethod: PaymentMethod::from((string) $validated['payment_method']),
            contactName: (string) $validated['contact']['name'],
            contactPhone: (string) $validated['contact']['phone'],
            contactEmail: $validated['contact']['email'] ?? null,
            deliveryAddressLine: $validated['delivery_address_line'] ?? null,
            scheduledAt: isset($validated['scheduled_at'])
                ? new DateTimeImmutable((string) $validated['scheduled_at'])
                : null,
            note: $validated['note'] ?? null,
            items: array_values(array_map(
                static fn (array $item): CreateOrderLineInput => new CreateOrderLineInput(
                    menuItemId: (string) $item['menu_item_id'],
                    quantity: (int) $item['quantity'],
                    note: $item['note'] ?? null,
                ),
                $items,
            )),
            expectedTotalAmount: isset($validated['expected_total_amount'])
                ? Money::fromString((string) $validated['expected_total_amount'])
                : null,
        );
    }
}
