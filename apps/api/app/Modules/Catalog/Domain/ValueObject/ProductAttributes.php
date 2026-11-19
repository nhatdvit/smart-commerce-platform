<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\ValueObject;

use App\Shared\Domain\Exception\BusinessRuleViolation;
use App\Shared\Presentation\Http\ErrorCode;

/**
 * The structured side of a product: ingredients, allergens, spice level, tags.
 *
 * Structured rather than prose, because a later search, filter or
 * recommendation has to read "contains peanuts" as data; parsing it back out of
 * a description would be guesswork (CLAUDE.md section 11).
 *
 * Framework-free by design: this is domain code, so it validates itself and
 * knows nothing about requests, Eloquent or JSON columns.
 */
final readonly class ProductAttributes
{
    public const int MAX_SPICE_LEVEL = 5;

    /**
     * @param  list<string>  $ingredients
     * @param  list<string>  $allergens
     * @param  list<string>  $tags
     * @param  list<string>  $dietary
     */
    private function __construct(
        public array $ingredients,
        public array $allergens,
        public ?int $spiceLevel,
        public array $tags,
        public array $dietary,
    ) {}

    public static function empty(): self
    {
        return new self([], [], null, [], []);
    }

    /**
     * Build from the transport or persistence representation.
     *
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $spiceLevel = $values['spice_level'] ?? null;

        if ($spiceLevel !== null && ! is_int($spiceLevel)) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                'Spice level must be a whole number or absent',
            );
        }

        if ($spiceLevel !== null && ($spiceLevel < 0 || $spiceLevel > self::MAX_SPICE_LEVEL)) {
            throw new BusinessRuleViolation(
                ErrorCode::ValidationFailed,
                sprintf('Spice level must be between 0 and %d', self::MAX_SPICE_LEVEL),
            );
        }

        return new self(
            self::stringList($values['ingredients'] ?? []),
            self::stringList($values['allergens'] ?? []),
            $spiceLevel,
            self::stringList($values['tags'] ?? []),
            self::stringList($values['dietary'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ingredients' => $this->ingredients,
            'allergens' => $this->allergens,
            'spice_level' => $this->spiceLevel,
            'tags' => $this->tags,
            'dietary' => $this->dietary,
        ];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $clean = [];

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $clean[] = trim($value);
            }
        }

        return array_values(array_unique($clean));
    }
}
