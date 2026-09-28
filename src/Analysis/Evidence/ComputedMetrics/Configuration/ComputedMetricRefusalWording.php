<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration;

/**
 * The words of every refusal `computed_metrics:` and `exclude_health:` raise
 * about what a value means — an invalid level, name or dimension, or an
 * invalid formula — held in one place so the entry reader, the name resolver
 * and the formula validator stay consistent. Unknown keys and the form of a
 * value are refused by the configuration document in its own words.
 */
final class ComputedMetricRefusalWording
{
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

    /**
     * @param string $where the item as its layer names it: a key path in a file, an option on the command line
     * @param list<string> $known full dimension names
     */
    public static function unknownExcludedHealthDimension(string $written, string $where, array $known): string
    {
        return \sprintf(
            'Unknown health dimension "%s" in %s. Valid dimensions: %s.',
            $written,
            $where,
            implode(', ', $known),
        );
    }

    public static function invalidFormulaSyntax(string $metricName, ?string $level, string $reason, string $formula): string
    {
        return \sprintf(
            'Invalid formula syntax for computed metric "%s"%s: %s (formula: %s)',
            $metricName,
            $level === null ? '' : \sprintf(' at level "%s"', $level),
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

    /**
     * A formula reading another computed metric at a level that metric does
     * not declare — the key is spelled right and exists, so the sentence names
     * where it IS published rather than sending the reader after a typo.
     *
     * @param non-empty-array<string, list<string>> $publishedAt reference => the levels it declares
     */
    public static function readsComputedMetricNotPublishedAtLevel(
        string $metricName,
        array $publishedAt,
        string $level,
        string $formula,
    ): string {
        $single = \count($publishedAt) === 1;
        $where = [];
        foreach ($publishedAt as $reference => $levels) {
            $where[] = ($single ? '' : \sprintf('"%s": ', $reference)) . implode(', ', $levels);
        }

        return \sprintf(
            'Computed metric "%s" reads %s at level "%s", where %s not published (published at: %s).'
            . ' Guard the read with "?? <fallback>", or declare level "%s" on the metric it reads. Formula: %s',
            $metricName,
            implode(', ', array_map(static fn(string $key): string => \sprintf('"%s"', $key), array_keys($publishedAt))),
            $level,
            $single ? 'it is' : 'they are',
            implode('; ', $where),
            $level,
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
