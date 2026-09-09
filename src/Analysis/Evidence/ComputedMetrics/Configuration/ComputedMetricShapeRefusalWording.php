<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration;

/**
 * The words of every `computed_metrics:` refusal that answers a plain type
 * mismatch — a value written where a map, a list, a string, a boolean or a
 * number was expected — as opposed to {@see ComputedMetricRefusalWording},
 * which holds the domain-semantic refusals (level, name and formula
 * validity). Split out of that class to keep each file's sentence count
 * readable; both stay in the same namespace, and the same three seams — the
 * key traversal, the entry reader, and the contribution reader — author
 * both files, so the two stay consistent with each other as the section
 * grows.
 *
 * Every sentence prints a key or a metric name exactly as the throw site
 * received it; only the *accepted* set is printed in the canonical spelling
 * declared in {@see ComputedMetricEntryKeys}.
 */
final class ComputedMetricShapeRefusalWording
{
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

    public static function levelListNotAList(string $metricName, mixed $written): string
    {
        return \sprintf(
            '"levels" of computed metric "%s" must be a list of level words, got %s.',
            $metricName,
            get_debug_type($written),
        );
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
}
