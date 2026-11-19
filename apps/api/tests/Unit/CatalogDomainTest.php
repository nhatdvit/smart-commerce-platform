<?php

declare(strict_types=1);

use App\Modules\Catalog\Domain\Entity\Product;
use App\Modules\Catalog\Domain\Entity\ProductVariant;
use App\Modules\Catalog\Domain\Enum\CatalogStatus;
use App\Modules\Catalog\Domain\ValueObject\ProductAttributes;
use App\Modules\Menu\Domain\Entity\MenuItem;
use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Domain\ValueObject\DisplayName;
use App\Shared\Domain\ValueObject\Money;

/*
 * Domain rules, with no database and no framework.
 *
 * These are the invariants that have to hold wherever a product comes from -
 * an API call, a seeder, an import - which is why they live in the domain and
 * can be tested without booting anything (CLAUDE.md sections 3 and 12).
 */

function variant(string $name = 'Mặc định', int $price = 25_000): ProductVariant
{
    return ProductVariant::create(null, null, $name, Money::vnd($price), CatalogStatus::Active);
}

function product(array $variants): Product
{
    return Product::create(
        null,
        '01930000-0000-7000-8000-000000000001',
        null,
        'Bánh mì pate trứng',
        'banh-mi-pate-trung',
        null,
        CatalogStatus::Active,
        true,
        null,
        'banh-mi',
        ProductAttributes::empty(),
        $variants,
    );
}

it('collapses the whitespace a pasted name carries', function (): void {
    expect(DisplayName::normalise("  Bánh   mì \n pate "))->toBe('Bánh mì pate');
});

it('folds Vietnamese case when comparing names', function (): void {
    // PostgreSQL's lower() does not do this on a C-collation cluster, which is
    // the whole reason the key is computed here.
    expect(DisplayName::key('BÁNH MÌ'))->toBe(DisplayName::key('bánh mì'))
        ->and(DisplayName::key('Xôi Gà'))->toBe('xôi gà');
});

it('refuses a product with no variant', function (): void {
    expect(static fn (): Product => product([]))
        ->toThrow(BusinessRuleViolation::class, 'A product needs at least one variant');
});

it('refuses two variants whose names differ only in case', function (): void {
    expect(static fn (): Product => product([variant('Lớn'), variant('LỚN')]))
        ->toThrow(BusinessRuleViolation::class);
});

it('refuses a negative variant price', function (): void {
    expect(static fn (): ProductVariant => variant('Mặc định', -1))
        ->toThrow(BusinessRuleViolation::class, 'A variant price cannot be negative');
});

it('refuses a blank name', function (): void {
    expect(static fn (): ProductVariant => variant('   '))
        ->toThrow(BusinessRuleViolation::class, 'A variant needs a name');
});

it('treats a blank sku as absent rather than empty', function (): void {
    // An empty string in a unique column is a value; "no sku" is not.
    expect(ProductVariant::create(null, '  ', 'Mặc định', Money::zero(), CatalogStatus::Active)->sku)
        ->toBeNull();
});

it('knows an archived product is not listable', function (): void {
    expect(CatalogStatus::Archived->isListable())->toBeFalse()
        ->and(CatalogStatus::Draft->isListable())->toBeFalse()
        ->and(CatalogStatus::Active->isListable())->toBeTrue();
});

it('keeps spice level inside its range', function (): void {
    expect(ProductAttributes::fromArray(['spice_level' => 3])->spiceLevel)->toBe(3)
        ->and(static fn (): ProductAttributes => ProductAttributes::fromArray(['spice_level' => 9]))
        ->toThrow(BusinessRuleViolation::class);
});

it('drops blank entries and duplicates from attribute lists', function (): void {
    $attributes = ProductAttributes::fromArray([
        'ingredients' => ['pate', ' pate ', '', 'trứng'],
        'tags' => 'not-an-array',
    ]);

    expect($attributes->ingredients)->toBe(['pate', 'trứng'])
        ->and($attributes->tags)->toBe([]);
});

it('refuses more portions left than were prepared', function (): void {
    expect(static fn (): MenuItem => MenuItem::create(
        null,
        '01930000-0000-7000-8000-000000000002',
        Money::vnd(25_000),
        true,
        40,
        30,
    ))->toThrow(BusinessRuleViolation::class, 'Remaining portions cannot exceed the portions prepared');
});

it('allows an uncounted item to have neither portions nor capacity', function (): void {
    $item = MenuItem::create(null, '01930000-0000-7000-8000-000000000002', Money::vnd(15_000));

    expect($item->availableQuantity)->toBeNull()
        ->and($item->dailyCapacity)->toBeNull()
        ->and($item->isAvailable)->toBeTrue();
});

it('keeps the price when only availability changes', function (): void {
    $item = MenuItem::create(null, '01930000-0000-7000-8000-000000000002', Money::vnd(25_000), true, 10, 10);
    $updated = $item->withAvailability(false, 0, 10);

    expect($updated->price->amount)->toBe(25_000)
        ->and($updated->isAvailable)->toBeFalse()
        ->and($updated->availableQuantity)->toBe(0);
});
