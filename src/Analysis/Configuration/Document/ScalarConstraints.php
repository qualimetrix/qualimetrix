<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeScalarFacts;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\TextRequirement;

/** Value constraints applied after a written scalar passes its declared form. */
final class ScalarConstraints
{
    /** @qmx-ignore code-smell.boolean-argument -- The boolean is a scalar value under validation, not a behavior switch. */
    public static function judge(NodeScalarFacts $facts, int|float|string|bool $value, ReadingContext $at): void
    {
        self::minimum($facts, $value, $at);
        self::nonBlank($facts, $value, $at);
        self::vocabulary($facts, $value, $at);
    }

    /** @qmx-ignore code-smell.boolean-argument -- The boolean belongs to the admitted scalar union; only numeric values have a minimum. */
    private static function minimum(NodeScalarFacts $facts, int|float|string|bool $value, ReadingContext $at): void
    {
        if ($facts->minimum !== null && (\is_int($value) || \is_float($value)) && $value < $facts->minimum) {
            throw $at->refusal(\sprintf('%s must be at least %s, got %s.', ucfirst($at->where()), $facts->minimum, $value));
        }
    }

    /** @qmx-ignore code-smell.boolean-argument -- The boolean belongs to the admitted scalar union; only text values have a blankness constraint. */
    private static function nonBlank(NodeScalarFacts $facts, int|float|string|bool $value, ReadingContext $at): void
    {
        if ($facts->textRequirement === TextRequirement::NonBlank && \is_string($value) && trim($value) === '') {
            throw $at->refusal(\sprintf('%s must be non-empty text.', ucfirst($at->where())));
        }
    }

    /** @qmx-ignore code-smell.boolean-argument -- The boolean belongs to the admitted scalar union; only text values have a word constraint. */
    private static function vocabulary(NodeScalarFacts $facts, int|float|string|bool $value, ReadingContext $at): void
    {
        if ($facts->words !== null && \is_string($value) && !$facts->words->contains($value)) {
            throw $at->refusal(\sprintf('%s must be one of %s, got "%s".', ucfirst($at->where()), implode(', ', $facts->words->words), $value));
        }
    }
}
