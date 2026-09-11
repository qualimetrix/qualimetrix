<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

/**
 * The words of every refusal raised when a rule option key is written where
 * the class at that depth does not answer for it.
 *
 * It sits beside {@see ChannelLevelRefusalWording} for that file's own reason:
 * a refusal that names a level is a formulation, and a formulation that could
 * be authored anywhere would let some other seam decide a level silently and
 * still sound like this one. Both halves of the seam — the judgement in
 * {@see \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionKeyRecognition} and
 * the wording here — change together.
 *
 * Every sentence prints the key **exactly as the walk received it**. By the
 * time a key arrives, every door has already folded its separators
 * ({@see \Qualimetrix\Analysis\Configuration\ConfigKeySpelling::normalize()}),
 * so there is no authored spelling left to quote and no inverse worth
 * guessing: a mistyped `max_warnign` is answered as `maxWarnign`. The letters
 * — which is what a typo gets wrong — survive the fold intact. The allowed
 * set, by contrast, is printed in the canonical kebab spelling the classes
 * declare, because that is the spelling users type.
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
            RuleOptionShape::describeWritten($written),
        );
    }
}
