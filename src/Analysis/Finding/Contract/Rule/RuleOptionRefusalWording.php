<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

/**
 * The words of every refusal raised when a rule option key is written where
 * the class at that depth does not answer for it.
 *
 * CLI option addressing uses these sentences when no declared option owns
 * the written key. The document reader judges file keys with its own schema
 * wording and preserves the authored path and origin.
 *
 * The key is printed exactly as the caller received it; no separator folding
 * or inverse spelling is performed here. Allowed options are printed in the
 * canonical kebab spelling supplied by the declaration surface.
 */
final class RuleOptionRefusalWording
{
    /**
     * @param list<string> $optionsHere canonical kebab spellings, sorted
     */
    public static function notAnOptionOfRule(string $key, string $ruleName, array $optionsHere): string
    {
        return \sprintf(
            'Option "%s" is not an option of rule "%s". Options here: %s.',
            $key,
            $ruleName,
            implode(', ', $optionsHere),
        );
    }

    /**
     * The last clause exists for the measured mistake this refusal answers:
     * one level's vocabulary written into another level's slot. A reader told
     * only that the key is unknown at `callable` will try the same key at
     * `class`.
     *
     * @param list<string> $optionsAtThatLevel canonical kebab spellings, sorted
     */
    public static function notAnOptionAtLevel(
        string $key,
        string $ruleName,
        string $level,
        array $optionsAtThatLevel,
    ): string {
        return \sprintf(
            'Option "%s" is not an option of rule "%s" at level "%s". Options at that level: %s.'
            . ' Other levels of this rule take different options.',
            $key,
            $ruleName,
            $level,
            implode(', ', $optionsAtThatLevel),
        );
    }

    /**
     * A slot holding something that is not a map of options.
     *
     * `false` gets a sentence of its own because it is the one non-map a user
     * plausibly means something by: a rule has a universal off-switch
     * (`rules: {X: false}`), a level has none, so `callable: false` reads like
     * one and does nothing. Every other non-map — a bare number, a string —
     * names no intention worth guessing at, so the advice is omitted rather
     * than invented.
     */
    public static function levelTakesAMapOfOptions(
        string $level,
        string $ruleName,
        mixed $written,
    ): string {
        $sentence = \sprintf(
            'Level "%s" of rule "%s" takes a map of options, got %s.',
            $level,
            $ruleName,
            get_debug_type($written),
        );

        return $written === false
            ? $sentence . \sprintf(' To switch one level off write "%s: {enabled: false}".', $level)
            : $sentence;
    }

    /**
     * A value whose form is not the one its key was declared with.
     *
     * Both halves of the sentence are named — what may stand there and what
     * was written — because a sentence carrying only the expectation leaves
     * the author guessing which of several values it is about. The written
     * form is described by what it is and never by what it was expected to
     * be, so the two halves cannot agree by accident.
     */
    public static function valueOfTheWrongShape(
        string $key,
        string $ruleName,
        ?string $level,
        RuleOptionShape $shape,
        mixed $written,
    ): string {
        return \sprintf(
            'Option "%s" of rule "%s"%s must be %s, got %s.',
            $key,
            $ruleName,
            $level === null ? '' : \sprintf(' at level "%s"', $level),
            $shape->describe(),
            $shape->describeWritten($written),
        );
    }
}
