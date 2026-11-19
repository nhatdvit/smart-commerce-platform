<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Request;

use App\Modules\Catalog\Application\DTO\ProductInput;
use App\Modules\Catalog\Application\DTO\VariantInput;
use App\Modules\Catalog\Domain\Enum\CatalogStatus;
use App\Modules\Catalog\Domain\ValueObject\ProductAttributes;
use App\Shared\Domain\ValueObject\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the `ProductRequest` payload for both create and update.
 *
 * One class for both, because the designed contract has one request schema for
 * both and a rule that holds on create but not on update is not a rule.
 *
 * These limits deliberately mirror the client-side rules in
 * `apps/admin-web/src/lib/product-form.ts`. The client's copy exists to give
 * immediate feedback in the form; this one is the rule, because the server is
 * the only party that cannot be bypassed (CLAUDE.md section 6).
 */
final class SaveProductRequest extends FormRequest
{
    /** Matches MAX_PRICE in the admin form: 100,000,000 dong. */
    public const int MAX_PRICE_AMOUNT = 100_000_000;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $statuses = array_column(CatalogStatus::cases(), 'value');

        return [
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:160', 'regex:/^[a-z0-9-]+$/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['required', 'string', Rule::in($statuses)],
            'inventory_tracking' => ['sometimes', 'boolean'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],

            // Two ways to say which category, and sending both is a 422 rather
            // than a guess. The pair is checked again in the application layer,
            // which is where the rule belongs for non-HTTP callers.
            'category_id' => ['sometimes', 'nullable', 'uuid', 'prohibits:category_name'],
            'category_name' => ['sometimes', 'nullable', 'string', 'max:120'],

            'attributes' => ['sometimes', 'array'],
            'attributes.ingredients' => ['sometimes', 'array', 'max:60'],
            'attributes.ingredients.*' => ['string', 'max:120'],
            'attributes.allergens' => ['sometimes', 'array', 'max:40'],
            'attributes.allergens.*' => ['string', 'max:120'],
            'attributes.spice_level' => ['sometimes', 'nullable', 'integer', 'between:0,'.ProductAttributes::MAX_SPICE_LEVEL],
            'attributes.tags' => ['sometimes', 'array', 'max:20'],
            'attributes.tags.*' => ['string', 'max:40'],
            'attributes.dietary' => ['sometimes', 'array', 'max:10'],
            'attributes.dietary.*' => ['string', Rule::in(['VEGETARIAN', 'VEGAN', 'HALAL', 'GLUTEN_FREE'])],

            'variants' => ['required', 'array', 'min:1', 'max:50'],
            'variants.*.id' => ['sometimes', 'nullable', 'uuid'],
            'variants.*.sku' => ['sometimes', 'nullable', 'string', 'max:64'],
            'variants.*.name' => ['required', 'string', 'max:160'],
            // Digits only, as a string. A JSON number cannot carry 64-bit dong
            // safely, and the digits-only pattern is what rejects a negative
            // price, "1e3" and "12.50" in one rule (CLAUDE.md section 6).
            'variants.*.base_price_amount' => [
                'required',
                'string',
                'regex:/^[0-9]+$/',
                'numeric',
                'max:'.self::MAX_PRICE_AMOUNT,
            ],
            'variants.*.status' => ['required', 'string', Rule::in($statuses)],

            // Designed, not built. Accepting and discarding them would be worse
            // than refusing: the caller would believe the modifiers were saved.
            'modifier_group_ids' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'variants.*.base_price_amount.regex' => 'Giá bán phải là số nguyên đồng, không âm.',
            'variants.*.base_price_amount.max' => 'Giá bán vượt mức cho phép.',
            'name.required' => 'Vui lòng nhập tên món.',
            'modifier_group_ids.prohibited' => 'Tuỳ chọn món (modifier) chưa được hỗ trợ trong giai đoạn này.',
        ];
    }

    /** The validated payload as the use case wants it: enums, Money, no transport types. */
    public function toInput(): ProductInput
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return new ProductInput(
            name: (string) $data['name'],
            status: CatalogStatus::from((string) $data['status']),
            variants: $this->variants($data['variants']),
            slug: $this->nullableString($data['slug'] ?? null),
            categoryId: $this->nullableString($data['category_id'] ?? null),
            categoryName: $this->nullableString($data['category_name'] ?? null),
            description: $this->nullableString($data['description'] ?? null),
            inventoryTracking: (bool) ($data['inventory_tracking'] ?? true),
            imageUrl: null,
            icon: $this->nullableString($data['icon'] ?? null),
            attributes: isset($data['attributes']) && is_array($data['attributes'])
                ? ProductAttributes::fromArray($data['attributes'])
                : null,
        );
    }

    /**
     * @return list<VariantInput>
     */
    private function variants(mixed $variants): array
    {
        $parsed = [];
        /** @var array<int, array<string, mixed>> $rows */
        $rows = array_values((array) $variants);

        foreach ($rows as $index => $variant) {
            $parsed[] = new VariantInput(
                id: $this->nullableString($variant['id'] ?? null),
                sku: $this->nullableString($variant['sku'] ?? null),
                name: (string) $variant['name'],
                basePrice: Money::fromString((string) $variant['base_price_amount']),
                status: CatalogStatus::from((string) $variant['status']),
                sortOrder: $index,
            );
        }

        return $parsed;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
