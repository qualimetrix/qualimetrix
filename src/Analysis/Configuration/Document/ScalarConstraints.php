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
        $facts = $schema->scalar;
        $advised = static function (string $summary) use ($schema, $value): string {
            $advice = $schema->wording->adviceFor($value);

            return $advice === null ? $summary : $summary . ' ' . $advice;
        };

        if ($facts->minimum !== null && (\is_int($value) || \is_float($value)) && $value < $facts->minimum) {
            throw $at->refusal($advised(\sprintf('%s must be at least %s, got %s.', ucfirst($at->where()), $facts->minimum, $value)));
        }

        if ($facts->textRequirement === TextRequirement::NonBlank && \is_string($value) && trim($value) === '') {
            throw $at->refusal($advised(\sprintf('%s must be non-empty text.', ucfirst($at->where()))));
        }

        if ($facts->words !== null && \is_string($value) && !$facts->words->contains($value)) {
            throw $at->refusal(
                $advised(\sprintf('%s must be one of %s, got "%s".', ucfirst($at->where()), implode(', ', $facts->words->words), $value)),
                $value,
                $facts->words->words,
            );
        }
    }
}
