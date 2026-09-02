<?php

declare(strict_types=1);

namespace QmxDirectiveAudit;

/**
 * Two site lists compared as multisets.
 *
 * A plain `array_diff()` treats its input as a set: two authored directives on
 * the same site collapse into one before the comparison runs, so a missing
 * occurrence with a surviving twin at the same site is invisible. Counting
 * occurrences per site keeps that case visible.
 */
final class Population
{
    /**
     * @param list<string> $left
     * @param list<string> $right
     *
     * @return array{0: array<string, int>, 1: array<string, int>} surplus in $left, surplus in $right
     */
    public static function diff(array $left, array $right): array
    {
        $leftCounts = self::occurrenceCounts($left);
        $rightCounts = self::occurrenceCounts($right);

        $onlyLeft = [];
        $onlyRight = [];

        foreach (array_unique([...array_keys($leftCounts), ...array_keys($rightCounts)]) as $site) {
            $delta = ($leftCounts[$site] ?? 0) - ($rightCounts[$site] ?? 0);

            if ($delta > 0) {
                $onlyLeft[$site] = $delta;
            } elseif ($delta < 0) {
                $onlyRight[$site] = -$delta;
            }
        }

        return [$onlyLeft, $onlyRight];
    }

    /**
     * @param list<string> $sites
     *
     * @return array<string, int>
     */
    private static function occurrenceCounts(array $sites): array
    {
        $counts = [];

        foreach ($sites as $site) {
            $counts[$site] = ($counts[$site] ?? 0) + 1;
        }

        return $counts;
    }
}
