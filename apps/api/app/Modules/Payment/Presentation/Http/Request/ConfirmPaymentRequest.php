<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Request;

use App\Modules\Payment\Application\DTO\ConfirmPaymentInput;
use App\Modules\Payment\Domain\Enum\OverpaymentDisposition;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `ConfirmPaymentRequest`.
 *
 * `amount` is sent explicitly rather than assumed from the order total, so
 * confirming the wrong figure takes a deliberate act and leaves a record of
 * it (CLAUDE.md section 6) - this Form Request only checks it is a shape of
 * whole đồng; whether it MATCHES what is owed is the domain entity's decision.
 */
final class ConfirmPaymentRequest extends FormRequest
{
    /** Matches MAX_AMOUNT in the coupon request; a sane ceiling on one payment. */
    public const int MAX_AMOUNT = 100_000_000;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:/^[0-9]+$/', 'numeric', 'max:'.self::MAX_AMOUNT],
            'bank_transaction_reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'observed_at' => ['sometimes', 'nullable', 'date'],
            'accept_discrepancy' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Whether this is actually required - the amount turns out to
            // exceed what is owed - depends on the payment this request has
            // no way to load, so that half of the rule lives in
            // ConfirmPayment::handle() instead; this only checks the shape.
            'overpayment_disposition' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(array_column(OverpaymentDisposition::cases(), 'value')),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.regex' => 'Số tiền phải là số nguyên đồng, không âm.',
        ];
    }

    public function toInput(): ConfirmPaymentInput
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return new ConfirmPaymentInput(
            amount: Money::fromString((string) $data['amount']),
            bankTransactionReference: isset($data['bank_transaction_reference']) && is_string($data['bank_transaction_reference'])
                ? trim($data['bank_transaction_reference'])
                : null,
            observedAt: $this->timestamp($data['observed_at'] ?? null),
            acceptDiscrepancy: (bool) ($data['accept_discrepancy'] ?? false),
            note: isset($data['note']) && is_string($data['note']) ? trim($data['note']) : null,
            overpaymentDisposition: isset($data['overpayment_disposition']) && is_string($data['overpayment_disposition'])
                ? OverpaymentDisposition::from($data['overpayment_disposition'])
                : null,
        );
    }

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
