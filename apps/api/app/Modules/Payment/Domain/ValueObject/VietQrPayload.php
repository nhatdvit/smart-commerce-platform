<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObject;

use App\Shared\Domain\ValueObject\Money;

/**
 * The EMVCo/VietQR string a customer's banking app scans.
 *
 * This is a TLV (tag-length-value) encoding - the EMVCo "Merchant Presented
 * Mode" format that every NAPAS VietQR-compatible banking app in Vietnam
 * reads - closed by a CRC-16/CCITT-FALSE checksum over everything before it.
 * Framework-free and side-effect-free: given the same inputs it always builds
 * the same string (CLAUDE.md sections 3 and 16).
 *
 * ## What is load-bearing, and what is not
 *
 * The bank BIN, the account number and the amount are what actually moves
 * money, and their encoding here follows the EMVCo spec precisely enough that
 * it is covered by exact-byte unit tests, including the CRC's own published
 * test vector. If the QR fails to scan, those three are not where to look.
 *
 * The one field this class is NOT fully certain of is which Additional Data
 * sub-tag a Vietnamese banking app prefills the transfer CONTENT from (tag 62,
 * sub-tag 08, "Purpose of Transaction" in EMVCo's own numbering - this is
 * standard EMVCo, not a NAPAS invention, but different banking apps are known
 * to read it with varying fidelity). Getting it wrong costs nothing but
 * convenience: the reference is also shown as plain copyable text next to the
 * QR (CLAUDE.md section 6), so a customer can type it by hand if their app
 * does not autofill it. **Scan a generated code with at least one real
 * Vietnamese banking app before this goes live.**
 *
 * @see https://www.napas.com.vn VietQR is NAPAS's standard; GUID A000000727 is
 *      its registered EMVCo identifier.
 */
final readonly class VietQrPayload
{
    /** NAPAS's EMVCo-registered application identifier for VietQR. */
    private const string NAPAS_GUID = 'A000000727';

    /** "Transfer to an account", as opposed to QRIBFTTC - to a card. */
    private const string SERVICE_CODE_ACCOUNT = 'QRIBFTTA';

    /** ISO 4217 numeric code for VND. */
    private const string CURRENCY_VND = '704';

    private const string COUNTRY_VN = 'VN';

    private function __construct(public string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }

    public static function build(
        string $bankBin,
        string $accountNumber,
        string $accountName,
        Money $amount,
        TransferReference $reference,
        string $merchantCity = 'VIETNAM',
    ): self {
        $merchantAccountInfo =
            self::tlv('00', self::NAPAS_GUID)
            .self::tlv('01', self::tlv('00', $bankBin).self::tlv('01', $accountNumber))
            .self::tlv('02', self::SERVICE_CODE_ACCOUNT);

        $additionalData = self::tlv('08', $reference->value);

        $body =
            self::tlv('00', '01') // Payload Format Indicator
            .self::tlv('01', '12') // Point of Initiation: dynamic (amount is fixed)
            .self::tlv('38', $merchantAccountInfo)
            .self::tlv('53', self::CURRENCY_VND)
            // Whole đồng, no decimal point: VND has no minor unit, and this
            // is itself a safeguard against the float arithmetic CLAUDE.md
            // section 6 forbids ever reaching a payment amount.
            .self::tlv('54', (string) $amount->amount)
            .self::tlv('58', self::COUNTRY_VN)
            .self::tlv('59', self::merchantField($accountName, 25))
            .self::tlv('60', self::merchantField($merchantCity, 15))
            .self::tlv('62', $additionalData);

        // The checksum covers the "6304" tag-and-length prefix of itself, so
        // it is appended to the body before the CRC is computed over it.
        $withCrcTag = $body.'6304';

        return new self($withCrcTag.self::crc16($withCrcTag));
    }

    private static function tlv(string $tag, string $value): string
    {
        return $tag.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }

    /**
     * Upper-case ASCII only, EMVCo's own requirement for these two fields.
     *
     * Vietnamese names arrive with diacritics; a banking app that cannot
     * render them would show nothing better than mojibake, so they are
     * stripped to their unaccented Latin letters instead.
     */
    private static function merchantField(string $value, int $maxLength): string
    {
        $folded = self::stripVietnameseDiacritics($value);
        $ascii = preg_replace('/[^A-Z0-9 ]/', '', strtoupper($folded)) ?? '';
        $trimmed = trim(preg_replace('/\s+/', ' ', $ascii) ?? '');

        return substr($trimmed === '' ? 'NA' : $trimmed, 0, $maxLength);
    }

    private static function stripVietnameseDiacritics(string $value): string
    {
        static $map = null;

        if ($map === null) {
            $map = self::diacriticsMap();
        }

        return strtr($value, $map);
    }

    /** @return array<string, string> */
    private static function diacriticsMap(): array
    {
        $groups = [
            'A' => 'ÀÁẢÃẠĂẰẮẲẴẶÂẦẤẨẪẬ',
            'E' => 'ÈÉẺẼẸÊỀẾỂỄỆ',
            'I' => 'ÌÍỈĨỊ',
            'O' => 'ÒÓỎÕỌÔỒỐỔỖỘƠỜỚỞỠỢ',
            'U' => 'ÙÚỦŨỤƯỪỨỬỮỰ',
            'Y' => 'ỲÝỶỸỴ',
            'D' => 'Đ',
        ];

        $map = [];

        foreach ($groups as $plain => $accented) {
            foreach (mb_str_split($accented) as $char) {
                $map[$char] = $plain;
                $map[mb_strtolower($char)] = $plain;
            }
        }

        return $map;
    }

    /** CRC-16/CCITT-FALSE: poly 0x1021, init 0xFFFF, no reflection, no final XOR. */
    private static function crc16(string $data): string
    {
        $crc = 0xFFFF;

        foreach (str_split($data) as $char) {
            $crc ^= ord($char) << 8;

            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) !== 0
                    ? (($crc << 1) ^ 0x1021) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
        }

        return str_pad(strtoupper(dechex($crc)), 4, '0', STR_PAD_LEFT);
    }
}
