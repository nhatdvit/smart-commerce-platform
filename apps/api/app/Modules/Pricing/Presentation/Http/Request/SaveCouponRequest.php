<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Presentation\Http\Request;

use App\Modules\Pricing\Application\DTO\CouponInput;
use App\Modules\Pricing\Domain\Enum\CouponStatus;
use App\Modules\Pricing\Domain\Enum\DiscountType;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a coupon, for both create and edit.
 *
 * Shape only. Whether the numbers make sense together - a percentage inside
 * 1..100, a fixed amount no bigger than the order it requires, a ceiling only
 * on a percentage - is the domain's answer, because a seeder and a future
 * import have to be held to the same rules (CLAUDE.md section 3).
 *
 * `redeemed_count` is deliberately not accepted: how many times a code has
 * been used is the system's record, not a form field.
 */
final class SaveCouponRequest extends FormRequest
{
    /** Matches MAX_PRICE in the admin form. */
    public const int MAX_AMOUNT = 100_000_000;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:3', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],

            'discount_type' => [
                'required',
                'string',
                Rule::in(array_column(DiscountType::cases(), 'value')),
            ],
            // A percentage or an amount, decided by discount_type; the domain
            // judges the pair.
            'discount_value' => ['required', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            'max_discount_amount' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            'min_order_amount' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT],

            'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],

            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],

            'status' => ['required', 'string', Rule::in(array_column(CouponStatus::cases(), 'value'))],

            // Designed for later: a limit per customer needs an identity to
            // count against, and guest checkout has no answer to that yet
            // (CLAUDE.md section 15). Accepting it would promise a rule
            // nothing enforces.
            'usage_limit_per_customer' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required' => 'Vui lòng nhập mã ưu đãi.',
            'code.regex' => 'Mã chỉ gồm chữ, số, gạch ngang hoặc gạch dưới.',
            'code.min' => 'Mã phải từ 3 ký tự trở lên.',
            'discount_value.min' => 'Mức giảm phải lớn hơn 0.',
            'usage_limit.min' => 'Giới hạn lượt dùng phải từ 1; để trống nghĩa là không giới hạn.',
            'usage_limit_per_customer.prohibited' => 'Giới hạn theo từng khách sẽ có khi khách vãng lai được định danh.',
        ];
    }

    public function toInput(): CouponInput
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        $maxDiscount = $this->nullableInt($data['max_discount_amount'] ?? null);
        $minOrder = $this->nullableInt($data['min_order_amount'] ?? null);

        return new CouponInput(
            code: (string) $data['code'],
            description: isset($data['description']) && is_string($data['description'])
                ? $data['description']
                : null,
            discountType: DiscountType::from((string) $data['discount_type']),
            discountValue: (int) $data['discount_value'],
            maxDiscount: $maxDiscount === null ? null : Money::vnd($maxDiscount),
            minOrder: Money::vnd($minOrder ?? 0),
            usageLimit: $this->nullableInt($data['usage_limit'] ?? null),
            startsAt: $this->timestamp($data['starts_at'] ?? null),
            endsAt: $this->timestamp($data['ends_at'] ?? null),
            status: CouponStatus::from((string) $data['status']),
        );
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Parses a bound into UTC, which is what the column holds.
     *
     * A client sends an instant (the admin form builds one from the store's
     * own clock); the API stores UTC and the screen renders it back
     * (CLAUDE.md section 6).
     */
    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $value)
            ?: new DateTimeImmutable($value, new DateTimeZone('UTC'));

        return $parsed->setTimezone(new DateTimeZone('UTC'));
    }
}
