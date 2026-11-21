<?php

declare(strict_types=1);

use App\Modules\Payment\Domain\ValueObject\TransferReference;
use App\Modules\Payment\Domain\ValueObject\VietQrPayload;
use App\Shared\Domain\ValueObject\Money;

/*
 * The EMVCo/VietQR encoder, with no database and no framework.
 *
 * The CRC and the TLV framing are checked byte-for-byte against the published
 * spec, independent of any business rule - the class docblock is explicit
 * that this is the part confidently verifiable without scanning a real
 * banking app (CLAUDE.md section 12: do not claim a test passed that was
 * never run).
 */

/** Parses a flat EMVCo TLV string back into tag => value, non-recursively. */
function parseTlv(string $data): array
{
    $fields = [];
    $offset = 0;

    while ($offset < strlen($data)) {
        $tag = substr($data, $offset, 2);
        $length = (int) substr($data, $offset + 2, 2);
        $value = substr($data, $offset + 4, $length);
        $fields[$tag] = $value;
        $offset += 4 + $length;
    }

    return $fields;
}

it('verifies CRC-16/CCITT-FALSE against its own published test vector', function (): void {
    // "123456789" is the standard conformance string for this exact CRC
    // variant (poly 0x1021, init 0xFFFF, no reflection, no final XOR); its
    // published check value is 0x29B1. Reached via reflection because the
    // algorithm is intentionally private - nothing outside this class should
    // ever call it directly - but its correctness has nothing to do with
    // VietQR and everything to do with getting the published spec right.
    $method = new ReflectionMethod(VietQrPayload::class, 'crc16');
    $method->setAccessible(true);

    expect($method->invoke(null, '123456789'))->toBe('29B1');
});

it('builds a well-formed EMVCo TLV string with the right amount and account', function (): void {
    $reference = TransferReference::create('SCP20261121A1B2');

    $payload = VietQrPayload::build(
        '970436',
        '0071000123456',
        // 25 characters exactly - the EMVCo ceiling for this field - chosen so
        // this test is about the TLV structure, not the truncation the
        // "stays within EMVCo field-length limits" test covers separately.
        'HO KINH DOANH BEP NHA MIN',
        Money::vnd(45_000),
        $reference,
    );

    $fields = parseTlv($payload->value);

    expect($fields['00'])->toBe('01') // Payload Format Indicator
        ->and($fields['01'])->toBe('12') // dynamic
        ->and($fields['53'])->toBe('704') // VND
        // Whole đồng, no decimal point - never a float near money.
        ->and($fields['54'])->toBe('45000')
        ->and($fields['58'])->toBe('VN')
        ->and($fields['59'])->toBe('HO KINH DOANH BEP NHA MIN')
        ->and($fields['63'])->toHaveLength(4);

    $merchantAccount = parseTlv($fields['38']);
    expect($merchantAccount['00'])->toBe('A000000727') // NAPAS GUID
        ->and($merchantAccount['02'])->toBe('QRIBFTTA');

    $beneficiary = parseTlv($merchantAccount['01']);
    expect($beneficiary['00'])->toBe('970436')
        ->and($beneficiary['01'])->toBe('0071000123456');

    $additionalData = parseTlv($fields['62']);
    expect($additionalData['08'])->toBe($reference->value);
});

it('produces a CRC that actually matches what it is supposed to check', function (): void {
    $payload = VietQrPayload::build(
        '970436',
        '0071000123456',
        'HO KINH DOANH BEP NHA MINH',
        Money::vnd(45_000),
        TransferReference::create('SCP20261121A1B2'),
    );

    // The checksum is computed over everything up to and including its own
    // "6304" tag-and-length prefix; recomputing it independently here and
    // comparing is the one assertion that would catch a boundary mistake -
    // one byte off either way - that parseTlv() alone cannot see. The "6304"
    // prefix is already the last 4 bytes of $withoutCrc, so it must not be
    // appended again here.
    $withoutCrc = substr($payload->value, 0, -4);
    $claimedCrc = substr($payload->value, -4);

    $method = new ReflectionMethod(VietQrPayload::class, 'crc16');
    $method->setAccessible(true);

    expect($claimedCrc)->toBe($method->invoke(null, $withoutCrc));
});

it('folds Vietnamese diacritics to plain upper-case ASCII', function (): void {
    $payload = VietQrPayload::build(
        '970436',
        '0071000123456',
        'Nguyễn Thị Thảo',
        Money::vnd(10_000),
        TransferReference::create('SCP20261121A1B2'),
    );

    $fields = parseTlv($payload->value);

    expect($fields['59'])->toBe('NGUYEN THI THAO');
});

it('stays within EMVCo field-length limits for a long name', function (): void {
    $payload = VietQrPayload::build(
        '970436',
        '0071000123456',
        'HO KINH DOANH BANH MI PATE TRUNG CHA LUA XOI GA RAT LA DAI TEN QUA',
        Money::vnd(10_000),
        TransferReference::create('SCP20261121A1B2'),
    );

    $fields = parseTlv($payload->value);

    expect(mb_strlen($fields['59']))->toBeLessThanOrEqual(25);
});
