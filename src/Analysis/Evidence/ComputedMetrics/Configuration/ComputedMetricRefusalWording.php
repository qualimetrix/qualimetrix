<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration;

/**
 * The words of every domain-semantic refusal `computed_metrics:` raises —
 * an unknown key, an invalid level or name, or an invalid formula — held in
 * one place for the same reason {@see \Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionRefusalWording}
 * is: a refusal that names a key or a level is a formulation, and three
 * different seams author one here — the key traversal, the entry reader, and
 * the name resolver. Keeping them in one file is what keeps the three
 * consistent with each other as the section grows a fourth.
 *
 * Plain type-mismatch sentences (a value written where a map, list, string,
 * boolean or number was expected) live in
 * {@see ComputedMetricShapeRefusalWording} instead — a different kind of
 * formulation, split out to keep each file's sentence count readable.
 *
 * Every sentence prints a key or a metric name exactly as the throw site
 * received it; only the *accepted* set is printed in the canonical spelling
 * declared in {@see ComputedMetricEntryKeys}.
 */
final class ComputedMetricRefusalWording
{
    /** @param list<string> $acceptedHere canonical spellings, sorted */
    public static function notAnEntryKey(string $key, string $metricName, array $acceptedHere): string
    {
        return \sprintf(
            'Option "%s" is not an option of computed metric "%s". Options here: %s.',
            $key,
            $metricName,
            implode(', ', $acceptedHere),
        );
    }

    /**
     * A `formulas:` key naming a real level word this capability does not
     * report at (`callable`, `file`) — distinguished from
     * {@see self::formulaKeyNotALevelAtAll()} for the same reason
     * `RuleOptionRefusalWording::notAnOptionAtLevel()` is: a reader told only
     * "unknown" will try the same word somewhere else in the vocabulary, when
     * the real problem is that this capability never reports there.
     *
     * @param list<string> $reportingLevels canonical level words, sorted
     */
    public static function formulaKeyNotAReportingLevel(string $key, string $metricName, array $reportingLevels): string
    {
        return \sprintf(
            'Computed metric "%s" declares a formula for level "%s", which is a real level but not one this'
            . ' capability reports at. "formulas" keys here: %s.',
            $metricName,
            $key,
            implode(', ', $reportingLevels),
        );
    }

    public static function formulaKeyNotALevelAtAll(string $key, string $metricName): string
    {
        return \sprintf('Computed metric "%s" declares a formula for "%s", which is not a level at all.', $metricName, $key);
    }

    /** @param list<string> $reportingLevels canonical level words, sorted */
    public static function levelWordNotAReportingLevel(string $level, array $reportingLevels): string
    {
        $words = array_map(static fn(string $word): string => \sprintf('"%s"', $word), $reportingLevels);
        $last = array_pop($words);

        return \sprintf(
            'Computed metric level "%s" is not supported; computed metrics report at %s only.',
            $level,
            implode(', ', $words) . ' or ' . $last,
        );
    }

    public static function levelWordNotALevelAtAll(string $level): string
    {
        return \sprintf('Invalid computed metric level: "%s"', $level);
    }

    public static function duplicateLevel(string $metricName): string
    {
        return \sprintf('Computed metric "%s" declares the same level more than once', $metricName);
    }

    public static function thresholdMixedWithGraduated(): string
    {
        return 'Cannot mix "threshold" with "warning"/"error". Use either "threshold" alone (simple mode) or'
            . ' "warning"/"error" (graduated mode).';
    }

    public static function nameGrammar(string $name, string $template): string
    {
        return \sprintf(
            'Computed metric name "%s" must be "health.<name>" or "computed.<name>", where every segment is'
            . ' lower-case kebab (%s) and the last segment is not the name of an aggregation strategy',
            $name,
            $template,
        );
    }

    public static function nameEndsInALevelWord(string $name, string $levelWord, string $levelSeparator): string
    {
        return \sprintf(
            'Computed metric name "%s" must not end in the level word "%s". '
            . 'A level is addressed beside the channel name, with "%s%s", not inside the name.',
            $name,
            $levelWord,
            $levelSeparator,
            $levelWord,
        );
    }

    /** @param list<string> $acceptedNames the six short health dimension names, sorted */
    public static function unknownHealthDimension(string $name, array $acceptedNames): string
    {
        return \sprintf(
            'Computed metric name "%s" is not a known "health.*" dimension. Valid dimensions: %s.',
            $name,
            implode(', ', array_map(static fn(string $n): string => 'health.' . $n, $acceptedNames)),
        );
    }

    public static function invalidFormulaSyntax(string $metricName, string $level, string $reason, string $formula): string
    {
        return \sprintf(
            'Invalid formula syntax for computed metric "%s" at level "%s": %s (formula: %s)',
            $metricName,
            $level,
            $reason,
            $formula,
        );
    }

    public static function everyAccessMustBeALiteralIndex(string $metricName, string $formula): string
    {
        return \sprintf(
            'Computed metric "%s" reaches "m" by something other than a quoted metric key, which makes'
            . ' the key unverifiable. Write every access as m["<metric key>"]. Formula: %s',
            $metricName,
            $formula,
        );
    }

    public static function noFormulaForLevel(string $metricName, string $level): string
    {
        return \sprintf('Computed metric "%s" has no formula for level "%s"', $metricName, $level);
    }

    /** @param list<string> $cycle */
    public static function circularDependency(array $cycle): string
    {
        return \sprintf('Circular dependency detected in computed metrics: %s', implode(' -> ', $cycle));
    }

    public static function referencesUnknownMetric(string $metricName, string $reference, string $formula): string
    {
        return \sprintf(
            'Computed metric "%s" references unknown metric "%s" in formula: %s',
            $metricName,
            $reference,
            $formula,
        );
    }

    /**
     * A formula naming a metric the product publishes, but not at the level the
     * formula is declared for — distinguished from
     * {@see self::referencesUnknownMetricKey()} because the key itself is
     * spelled correctly and the reader would otherwise hunt for a typo that is
     * not there.
     *
     * @param list<string> $keys as the formula spells them
     */
    public static function referencesMetricAbsentAtLevel(
        string $metricName,
        array $keys,
        string $level,
        string $formula,
    ): string {
        return \sprintf(
            'Computed metric "%s" reads %s at level "%s", where no symbol carries %s.'
            . ' Guard the read with "?? <fallback>" or declare the formula at a level that publishes it. Formula: %s',
            $metricName,
            implode(', ', array_map(static fn(string $key): string => \sprintf('"%s"', $key), $keys)),
            $level,
            \count($keys) === 1 ? 'it' : 'them',
            $formula,
        );
    }

    public static function referencesUnknownMetricKey(string $metricName, string $key, string $formula): string
    {
        return \sprintf(
            'Computed metric "%s" references unknown metric key "%s" in formula: %s',
            $metricName,
            $key,
            $formula,
        );
    }
}
