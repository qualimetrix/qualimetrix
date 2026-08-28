<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

/**
 * Reads the metric keys a formula addresses.
 *
 * A formula sees one variable, `m`, and reaches a metric by its own published
 * key: `m['complexity.ccn.avg']`. The encoding this replaced turned `a.b` into
 * the identifier `a__b` because Expression Language forbids a dot in a name;
 * with kebab keys it would have to forbid the hyphen too, and the guard that
 * kept metric names free of `__` existed only to protect that encoding.
 *
 * Reading the index as a literal is what keeps a typo detectable. Under the
 * encoding, `ccnn__avg` was an unknown VARIABLE and Expression Language
 * rejected it at parse time for free. One variable buys that for nothing, so
 * the check is restated here: the index must be a single-quoted literal, and a
 * computed index is refused loudly rather than validated as far as it can be.
 */
final class FormulaMetricReference
{
    private const string LITERAL_INDEX = '/\bm\[\s*(?:\'([^\']*)\'|"([^"]*)")\s*\]/';

    /** A key the formula guards with `??`, and therefore does not require. */
    private const string GUARDED_INDEX = '/\bm\[\s*(?:\'([^\']*)\'|"([^"]*)")\s*\]\s*\?\?/';

    /** Any `m[`, whatever follows it. */
    private const string ANY_INDEX = '/\\bm\\[/';

    /**
     * The metric keys a formula names, in order of first appearance.
     *
     * @return list<string>
     */
    public static function keysOf(string $formula): array
    {
        if (preg_match_all(self::LITERAL_INDEX, $formula, $matches) === false) {
            return [];
        }

        $keys = [];

        foreach ($matches[1] as $index => $single) {
            $keys[] = $single !== '' ? $single : $matches[2][$index];
        }

        return array_values(array_unique($keys));
    }

    /**
     * The keys a formula needs present, i.e. every key it names except those it
     * guards with `??`.
     *
     * @return list<string>
     */
    public static function requiredKeysOf(string $formula): array
    {
        $guarded = [];

        if (preg_match_all(self::GUARDED_INDEX, $formula, $matches) !== false) {
            foreach ($matches[1] as $index => $single) {
                $guarded[$single !== '' ? $single : $matches[2][$index]] = true;
            }
        }

        return array_values(array_filter(
            self::keysOf($formula),
            static fn(string $key): bool => !isset($guarded[$key]),
        ));
    }

    /**
     * @throws ComputedMetricConfigurationException if the formula indexes `m` with anything but a literal
     */
    public static function assertEveryIndexIsLiteral(string $formula, string $metricName): void
    {
        $literal = preg_match_all(self::LITERAL_INDEX, $formula);
        $any = preg_match_all(self::ANY_INDEX, $formula);

        if ($literal === false || $any === false || $literal === $any) {
            return;
        }

        throw new ComputedMetricConfigurationException(\sprintf(
            'Computed metric "%s" indexes m[] with something other than a quoted metric key,'
            . ' which makes the key unverifiable. Write the key as a literal. Formula: %s',
            $metricName,
            $formula,
        ));
    }

    private function __construct() {}
}
