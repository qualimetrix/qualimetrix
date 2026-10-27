<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\TextRequirement;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

/**
 * Value constraints applied after a written scalar passes its declared form,
 * each refusal carrying the node's advice for the refused value. A value
 * outside a word set is refused at a closed position naming the value and the
 * words.
 */
final class ScalarConstraints
{
    /**
     * @qmx-ignore code-smell.boolean-argument -- The boolean is a scalar value under validation, not a behavior switch.
     *
     * @throws ConfigurationRefusal
     */
    public static function judge(NodeSchema $schema, int|float|string|bool $value, ReadingContext $at): void
    {
        $summary = self::belowMinimum($schema, $value, $at) ?? self::blank($schema, $value, $at);
        if ($summary !== null) {
            throw $at->refusal(self::advised($schema, $value, $summary));
        }

        $words = $schema->scalar->words;
        if ($words !== null && \is_string($value) && !$words->contains($value)) {
            throw $at->refusal(
                self::advised($schema, $value, \sprintf('%s must be one of %s, got "%s".', ucfirst($at->where()), implode(', ', $words->words), $value)),
                $value,
                $words->words,
            );
        }
    }

    /** @qmx-ignore code-smell.boolean-argument -- The boolean belongs to the admitted scalar union; only numeric values have a minimum. */
    private static function belowMinimum(NodeSchema $schema, int|float|string|bool $value, ReadingContext $at): ?string
    {
        $minimum = $schema->scalar->minimum;

        return $minimum !== null && (\is_int($value) || \is_float($value)) && $value < $minimum
            ? \sprintf('%s must be at least %s, got %s.', ucfirst($at->where()), $minimum, $value)
            : null;
    }

    /** @qmx-ignore code-smell.boolean-argument -- The boolean belongs to the admitted scalar union; only text values have a blankness constraint. */
    private static function blank(NodeSchema $schema, int|float|string|bool $value, ReadingContext $at): ?string
    {
        return $schema->scalar->textRequirement === TextRequirement::NonBlank && \is_string($value) && trim($value) === ''
            ? \sprintf('%s must be non-empty text.', ucfirst($at->where()))
            : null;
    }

    /** @qmx-ignore code-smell.boolean-argument -- The boolean is the refused scalar a hint may be declared for. */
    private static function advised(NodeSchema $schema, int|float|string|bool $value, string $summary): string
    {
        $advice = $schema->wording->adviceFor($value);

        return $advice === null ? $summary : $summary . ' ' . $advice;
    }
}
