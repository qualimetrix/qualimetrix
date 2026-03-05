<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting;

use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;

/**
 * Sorts violations based on GroupBy mode.
 *
 * Sorting is deterministic and matches the grouping to ensure
 * violations within the same group appear together.
 */
final class ViolationSorter
{
    /**
     * @param list<Violation> $violations
     *
     * @return list<Violation>
     */
    public static function sort(array $violations, GroupBy $groupBy): array
    {
        usort($violations, match ($groupBy) {
            GroupBy::None => self::bySeverityFileLine(...),
            GroupBy::File => self::byFileSeverityLine(...),
            GroupBy::Rule => self::byRuleSeverityFileLine(...),
            GroupBy::Severity => self::bySeverityFileLine(...),
        });

        return $violations;
    }

    /**
     * Groups sorted violations by the grouping key.
     *
     * @param list<Violation> $violations Already sorted violations
     *
     * @return array<string, list<Violation>> Group key => violations
     */
    public static function group(array $violations, GroupBy $groupBy): array
    {
        $groups = [];

        foreach ($violations as $violation) {
            $key = match ($groupBy) {
                GroupBy::None => '',
                GroupBy::File => $violation->location->file,
                GroupBy::Rule => $violation->ruleName,
                GroupBy::Severity => $violation->severity->value,
            };

            $groups[$key][] = $violation;
        }

        return $groups;
    }

    private static function bySeverityFileLine(Violation $a, Violation $b): int
    {
        return self::severityOrder($a->severity) <=> self::severityOrder($b->severity)
            ?: $a->location->file <=> $b->location->file
            ?: ($a->location->line ?? 0) <=> ($b->location->line ?? 0);
    }

    private static function byFileSeverityLine(Violation $a, Violation $b): int
    {
        return $a->location->file <=> $b->location->file
            ?: self::severityOrder($a->severity) <=> self::severityOrder($b->severity)
            ?: ($a->location->line ?? 0) <=> ($b->location->line ?? 0);
    }

    private static function byRuleSeverityFileLine(Violation $a, Violation $b): int
    {
        return $a->ruleName <=> $b->ruleName
            ?: self::severityOrder($a->severity) <=> self::severityOrder($b->severity)
            ?: $a->location->file <=> $b->location->file
            ?: ($a->location->line ?? 0) <=> ($b->location->line ?? 0);
    }

    private static function severityOrder(Severity $severity): int
    {
        return match ($severity) {
            Severity::Error => 0,
            Severity::Warning => 1,
        };
    }
}
