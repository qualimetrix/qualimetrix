<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

/**
 * The words of every refusal {@see ChannelLevelAddressing} hands back — the
 * second half of the same seam, and never anything a caller may author for
 * itself.
 *
 * Splitting the seam in two keeps one subject per file: which condition of an
 * authored pair is unmet is a judgement, and how that is said to the author is
 * a formulation. They still change together, so this file sits beside the
 * judge rather than in a shared text utility: a refusal naming a level that
 * could be written anywhere would let a seam decide the pair silently and
 * still sound like the seam, which is what the wording detector exists to
 * catch.
 *
 * Nothing here asks the channel universe anything. Every sentence is built
 * from facts the judge already established, which is also why the levels a
 * channel declares arrive as plain strings.
 *
 * Each method takes the caller's own name for the text it read — its
 * `$subject` — and falls back to quoting the raw text when the caller has none.
 */
final class ChannelLevelRefusalWording
{
    /** @param list<string> $levelWords the whole level vocabulary */
    public static function noLevelAfterSeparator(?string $subject, string $raw, array $levelWords): string
    {
        return \sprintf(
            '%s names no level after "%s". A level is one of %s.',
            self::subjectOf($subject, $raw),
            ChannelLevelSelector::LEVEL_SEPARATOR,
            self::quotedList($levelWords),
        );
    }

    /**
     * The same mistake on a rule pair, which names the half it rejected: a
     * threshold author who mistyped the level has no other way to see which
     * half was read as one.
     *
     * @param list<string> $levelWords the whole level vocabulary
     */
    public static function ruleNamesNoLevel(string $subject, string $raw, array $levelWords): string
    {
        return \sprintf(
            '%s names no level after "%s": "%s" is not a level. A level is one of %s.',
            $subject,
            ChannelLevelSelector::LEVEL_SEPARATOR,
            ChannelLevelSelector::levelHalfText($raw) ?? '',
            self::quotedList($levelWords),
        );
    }

    public static function channelHalfIsNotASelector(
        ?string $subject,
        string $raw,
        string $channelHalf,
        string $level,
    ): string {
        return \sprintf(
            '%s is written as a channel-and-level pair, but "%s" is not a channel selector.'
            . ' Write an exact channel name, or "X.*" for the channels below X, then "%s%s".',
            self::subjectOf($subject, $raw),
            $channelHalf,
            ChannelLevelSelector::LEVEL_SEPARATOR,
            $level,
        );
    }

    public static function addressesNoChannel(?string $subject, string $raw, string $level): string
    {
        return \sprintf(
            '%s addresses no channel, so it cannot address one at level "%s".',
            self::subjectOf($subject, $raw),
            $level,
        );
    }

    public static function addressesNoneOf(?string $subject, string $raw, string $candidatesAre): string
    {
        return \sprintf('%s addresses none of %s.', self::subjectOf($subject, $raw), $candidatesAre);
    }

    public static function addressesNoneOfSoNoLevelEither(
        ?string $subject,
        string $raw,
        ?string $candidatesAre,
    ): string {
        return \sprintf(
            '%s addresses none of %s, so no level of one can be addressed either.',
            self::subjectOf($subject, $raw),
            $candidatesAre ?? '',
        );
    }

    /**
     * @param list<string> $channelCodes the channels the pair does address
     * @param list<string> $reportedLevels every level those channels declare
     */
    public static function noneReportsAtLevel(
        ?string $subject,
        string $channelHalf,
        array $channelCodes,
        string $level,
        array $reportedLevels,
    ): string {
        $one = \count($channelCodes) === 1;

        return \sprintf(
            '%s addresses %s, and %s at level "%s" — %s. The pair can never match anything.',
            // The channel half rather than the whole pair: without a subject of
            // the caller's own, the sentence has to name something, and the
            // half the levels are a property of is the useful one.
            $subject ?? \sprintf('Channel selector "%s"', $channelHalf),
            self::quotedList($channelCodes),
            // A wildcard addressing one channel is the common case, and "none
            // of them" about a single name reads as a different complaint than
            // the one being made.
            $one ? 'it does not report' : 'none of them reports',
            $level,
            $reportedLevels === []
                ? ($one ? 'it declares no level at all' : 'none of them declares a level at all')
                : 'the levels available are ' . self::quotedList($reportedLevels),
        );
    }

    public static function namesNoRule(string $subject, string $ruleHalf): string
    {
        return \sprintf('%s names no rule: "%s" is not a rule name.', $subject, $ruleHalf);
    }

    public static function thresholdIgnoresLevels(string $subject, string $ruleHalf, string $level): string
    {
        return \sprintf(
            '%s addresses a rule at a level, and a threshold addresses the producing rule by its own name: it does'
            . ' not distinguish levels (ADR 0024). Retune the whole rule "%s", or set the level alone with'
            . ' --rule-opt %s%s%s.<option>=<value>.',
            $subject,
            $ruleHalf,
            $ruleHalf,
            ChannelLevelSelector::LEVEL_SEPARATOR,
            $level,
        );
    }

    /**
     * The same mistake as {@see thresholdIgnoresLevels()}, made against a rule
     * that could not have been retuned at any level.
     *
     * Separate wording because the advice is the whole point of the other one,
     * and here every clause of that advice is false: "retune the whole rule"
     * names something the rule declares it cannot do, and
     * `--rule-opt X:level.<option>=<value>` is a command the CLI accepts,
     * warns about as an unknown option, and exits zero on — a no-op
     * recommended by the product itself.
     */
    public static function thresholdCannotBeRetunedAtAnyLevel(string $subject, string $ruleHalf, string $level): string
    {
        return \sprintf(
            '%s addresses rule "%s" at level "%s", and that rule declares no @qmx-threshold support: it cannot be'
            . ' retuned at that level or at any other. Remove the annotation, or configure the rule under its'
            . ' "rules:" key.',
            $subject,
            $ruleHalf,
            $level,
        );
    }

    private static function subjectOf(?string $subject, string $raw): string
    {
        return $subject ?? '"' . $raw . '"';
    }

    /**
     * Sorted, because every list here is prose in a refusal: its order is the
     * reader's convenience and not a statement about where the values came
     * from.
     *
     * @param list<string> $values
     */
    private static function quotedList(array $values): string
    {
        sort($values);

        return implode(', ', array_map(static fn(string $value): string => '"' . $value . '"', $values));
    }
}
