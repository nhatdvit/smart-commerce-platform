<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Entity;

use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;
use DateTimeImmutable;

/**
 * Where a store's bank transfers are received.
 *
 * A typo here does not fail loudly - it quietly sends a customer's money to
 * whoever actually holds that account number. The format checks below are not
 * a formality: they are the one thing standing between a fat-fingered digit
 * and a stranger's bank account, and the migration carries the same two CHECK
 * constraints so no later code path can bypass them either.
 *
 * Mutating one of these is never done to the row a live QR was generated
 * against - see the class note on `payments` in the settlement migration:
 * the beneficiary is snapshotted onto the payment at generation time, so an
 * account edit tomorrow cannot rewrite the instructions a customer paid
 * against yesterday.
 */
final readonly class BankAccount
{
    private const string BIN_FORMAT = '/^[0-9]{6}$/';

    private const string ACCOUNT_NUMBER_FORMAT = '/^[0-9]{6,20}$/';

    private function __construct(
        public ?string $id,
        public string $storeId,
        public string $bankBin,
        public string $bankName,
        public string $accountNumber,
        public string $accountName,
        public bool $isDefault,
        public bool $isActive,
        public ?string $updatedBy,
        public ?DateTimeImmutable $updatedAt,
    ) {}

    public static function create(
        ?string $id,
        string $storeId,
        string $bankBin,
        string $bankName,
        string $accountNumber,
        string $accountName,
        bool $isDefault = true,
        bool $isActive = true,
        ?string $updatedBy = null,
        ?DateTimeImmutable $updatedAt = null,
    ): self {
        $bankBin = trim($bankBin);
        $accountNumber = trim($accountNumber);
        $bankName = trim($bankName);
        $accountName = trim($accountName);

        if (preg_match(self::BIN_FORMAT, $bankBin) !== 1) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A bank identifier must be exactly 6 digits',
                [['field' => 'bank_bin', 'message' => 'Mã ngân hàng phải gồm đúng 6 chữ số.']],
            );
        }

        if (preg_match(self::ACCOUNT_NUMBER_FORMAT, $accountNumber) !== 1) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'An account number must be 6 to 20 digits',
                [['field' => 'account_number', 'message' => 'Số tài khoản phải gồm 6 đến 20 chữ số.']],
            );
        }

        if ($accountName === '') {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'A bank account needs the name it is registered under',
                [['field' => 'account_name', 'message' => 'Vui lòng nhập tên chủ tài khoản đúng như đăng ký với ngân hàng.']],
            );
        }

        return new self(
            $id,
            $storeId,
            $bankBin,
            $bankName,
            $accountNumber,
            $accountName,
            $isDefault,
            $isActive,
            $updatedBy,
            $updatedAt,
        );
    }

    /** `****3210` - enough to confirm which account without the whole number. */
    public function maskedAccountNumber(): string
    {
        return self::mask($this->accountNumber);
    }

    /**
     * Shared with {@see \App\Modules\Payment\Presentation\Http\Resource\AdminPaymentResource},
     * which masks the beneficiary SNAPSHOT on a payment rather than a live
     * `BankAccount` - the two have no entity in common, only this rule.
     */
    public static function mask(string $accountNumber): string
    {
        $visible = 4;
        $length = strlen($accountNumber);

        if ($length <= $visible) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - $visible).substr($accountNumber, -$visible);
    }
}
