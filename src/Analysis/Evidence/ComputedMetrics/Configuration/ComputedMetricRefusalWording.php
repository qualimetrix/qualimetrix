<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration;

/**
 * The words of every refusal `computed_metrics:` raises, held in one place for
 * the same reason {@see \Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionRefusalWording}
 * is: a refusal that names a key or a level is a formulation, and three
 * different seams author one here — the key traversal, the entry reader, and
 * the name resolver. Keeping them in one file is what keeps the three
 * consistent with each other as the section grows a fourth.
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
     * `false` gets a sentence of its own, the same way
     * {@see \Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionRefusalWording::levelTakesAMapOfOptions()}
     * does: an entry has a universal off switch (`{enabled: false}`), so a bare
     * `false` reads like a shorthand for it and does nothing. Every other
     * non-map names no intention worth guessing at.
     */
    public static function entryNotAMap(string $metricName, mixed $written): string
    {
        $sentence = \sprintf(
            'Computed metric entry "%s" must be a map of options, got %s.',
            $metricName,
            get_debug_type($written),
        );

        return $written === false
            ? $sentence . ' To disable this metric write "{enabled: false}".'
            : $sentence;
    }

    public static function formulasNotAMap(string $metricName, mixed $written): string
    {
        return \sprintf(
            '"formulas" of computed metric "%s" must be a map of level to formula, got %s.',
            $metricName,
            get_debug_type($written),
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

    public static function levelListNotAList(string $metricName, mixed $written): string
    {
        return \sprintf(
            '"levels" of computed metric "%s" must be a list of level words, got %s.',
            $metricName,
            get_debug_type($written),
        );
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

    public static function mustBeAString(string $metricName, string $key, mixed $written): string
    {
        return \sprintf(
            'Option "%s" of computed metric "%s" must be a string, got %s.',
            $key,
            $metricName,
            get_debug_type($written),
        );
    }

    public static function mustBeABoolean(string $metricName, string $key, mixed $written): string
    {
        return \sprintf(
            'Option "%s" of computed metric "%s" must be a boolean, got %s.',
            $key,
            $metricName,
            get_debug_type($written),
        );
    }

    public static function mustBeANumber(string $metricName, string $key, mixed $written): string
    {
        return \sprintf(
            'Option "%s" of computed metric "%s" must be a number or null, got %s.',
            $key,
            $metricName,
            get_debug_type($written),
        );
    }

    public static function formulaValueMustBeAString(string $metricName, string $level, mixed $written): string
    {
        return \sprintf(
            'The "formulas.%s" value of computed metric "%s" must be a string, got %s.',
            $level,
            $metricName,
            get_debug_type($written),
        );
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

    public static function computedMetricsSectionNotAMap(): string
    {
        return 'computed_metrics must be an associative map.';
    }

    public static function excludeHealthNotAList(): string
    {
        return 'exclude_health must be a list.';
    }

    public static function excludeHealthEntryNotAString(): string
    {
        return 'exclude_health entries must be strings.';
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
