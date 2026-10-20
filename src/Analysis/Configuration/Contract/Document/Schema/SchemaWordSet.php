<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document\Schema;

use LogicException;

/** A declared scalar vocabulary and the comparison its reader uses. */
final readonly class SchemaWordSet
{
    /** @param non-empty-list<string> $words */
    private function __construct(public array $words, public WordComparison $comparison) {}

    public static function of(string ...$words): self
    {
        return new self(self::validated(array_values($words)), WordComparison::Sensitive);
    }

    public static function foldingCase(string ...$words): self
    {
        return new self(self::validated(array_values($words)), WordComparison::Folding);
    }

    public function contains(string $value): bool
    {
        foreach ($this->words as $word) {
            if ($this->comparison === WordComparison::Folding ? strcasecmp($value, $word) === 0 : $value === $word) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $words
     * @return non-empty-list<string>
     */
    private static function validated(array $words): array
    {
        if ($words === []) {
            throw new LogicException('A word vocabulary requires at least one word.');
        }
        return $words;
    }
}
