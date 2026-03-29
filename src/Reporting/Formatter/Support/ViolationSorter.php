<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Support;

use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\GroupBy;

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
            GroupBy::ClassName => self::byClassSeverityLine(...),
            GroupBy::NamespaceName => self::byNamespaceSeverityLine(...),
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
                GroupBy::ClassName => self::extractClassName($violation),
                GroupBy::NamespaceName => self::extractNamespaceName($violation),
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

    private static function byClassSeverityLine(Violation $a, Violation $b): int
    {
        return self::extractClassName($a) <=> self::extractClassName($b)
            ?: self::severityOrder($a->severity) <=> self::severityOrder($b->severity)
            ?: $a->location->file <=> $b->location->file
            ?: ($a->location->line ?? 0) <=> ($b->location->line ?? 0);
    }

    private static function byNamespaceSeverityLine(Violation $a, Violation $b): int
    {
        return self::extractNamespaceName($a) <=> self::extractNamespaceName($b)
            ?: self::severityOrder($a->severity) <=> self::severityOrder($b->severity)
            ?: $a->location->file <=> $b->location->file
            ?: ($a->location->line ?? 0) <=> ($b->location->line ?? 0);
    }

    /**
     * Extracts the FQCN for grouping. Falls back to file path if no class context.
     */
    private static function extractClassName(Violation $violation): string
    {
        $sp = $violation->symbolPath;
        $ns = $sp->namespace ?? '';
        $type = $sp->type;

        if ($type !== null) {
            return $ns !== '' ? $ns . '\\' . $type : $type;
        }

        // File-level violation without class context — group under file path
        return $violation->location->file;
    }

    /**
     * Extracts the namespace for grouping. Falls back to '<global>' if no namespace.
     */
    private static function extractNamespaceName(Violation $violation): string
    {
        $ns = $violation->symbolPath->namespace ?? '';

        return $ns !== '' ? $ns : '<global>';
    }

    private static function severityOrder(Severity $severity): int
    {
        return match ($severity) {
            Severity::Error => 0,
            Severity::Warning => 1,
        };
    }
}
