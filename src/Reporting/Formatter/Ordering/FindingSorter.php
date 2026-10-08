<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Ordering;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Reporting\GroupBy;

/**
 * Sorts findings based on GroupBy mode.
 *
 * Sorting is deterministic and matches the grouping to ensure
 * findings within the same group appear together.
 */
final class FindingSorter
{
    /**
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    public static function sort(array $findings, GroupBy $groupBy): array
    {
        usort($findings, match ($groupBy) {
            GroupBy::None => self::bySeverityFileLine(...),
            GroupBy::File => self::byFileSeverityLine(...),
            GroupBy::Rule => self::byRuleSeverityFileLine(...),
            GroupBy::Severity => self::bySeverityFileLine(...),
            GroupBy::ClassName => self::byClassSeverityLine(...),
            GroupBy::NamespaceName => self::byNamespaceSeverityLine(...),
        });

        return $findings;
    }

    /**
     * Groups sorted findings by the grouping key.
     *
     * @param list<Finding> $findings Already sorted findings
     *
     * @return array<string, list<Finding>> Group key => findings
     */
    public static function group(array $findings, GroupBy $groupBy): array
    {
        $groups = [];

        foreach ($findings as $finding) {
            $key = match ($groupBy) {
                GroupBy::None => '',
                GroupBy::File => self::placeKey($finding),
                GroupBy::Rule => $finding->ruleName,
                GroupBy::Severity => $finding->severity->value,
                GroupBy::ClassName => self::extractClassName($finding),
                GroupBy::NamespaceName => self::extractNamespaceName($finding),
            };

            $groups[$key][] = $finding;
        }

        return $groups;
    }

    /**
     * Selects severity first, then the run's impact ranking, before grouping.
     *
     * @param list<Finding> $findings
     * @param list<\Qualimetrix\Analysis\Evidence\Prioritization\Impact\RankedIssue> $ranked
     *
     * @return list<Finding>
     */
    public static function worstFirst(array $findings, array $ranked, int $limit): array
    {
        $ranks = [];
        foreach ($ranked as $rank => $issue) {
            $ranks[$issue->finding->getFingerprint()] = $rank;
        }
        usort($findings, static function (Finding $a, Finding $b) use ($ranks): int {
            $comparisons = [
                self::severityOrder($a->severity) <=> self::severityOrder($b->severity),
                ($ranks[$a->getFingerprint()] ?? \PHP_INT_MAX) <=> ($ranks[$b->getFingerprint()] ?? \PHP_INT_MAX),
                self::placeKey($a) <=> self::placeKey($b),
                ($a->location->line ?? 0) <=> ($b->location->line ?? 0),
                $a->getFingerprint() <=> $b->getFingerprint(),
            ];
            foreach ($comparisons as $comparison) {
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
            return 0;
        });

        return \array_slice($findings, 0, $limit);
    }

    private static function placeKey(Finding $finding): string
    {
        return $finding->location->file === null
            ? \Qualimetrix\Reporting\Formatter\PublishedFinding::place($finding)->name
            : $finding->location->pathString();
    }

    private static function bySeverityFileLine(Finding $a, Finding $b): int
    {
        return ($cmp1 = self::severityOrder($a->severity) <=> self::severityOrder($b->severity)) !== 0 ? $cmp1
            : (($cmp2 = $a->location->pathString() <=> $b->location->pathString()) !== 0 ? $cmp2
            : (($a->location->line ?? 0) <=> ($b->location->line ?? 0)));
    }

    private static function byFileSeverityLine(Finding $a, Finding $b): int
    {
        return ($cmp1 = $a->location->pathString() <=> $b->location->pathString()) !== 0 ? $cmp1
            : (($cmp2 = self::severityOrder($a->severity) <=> self::severityOrder($b->severity)) !== 0 ? $cmp2
            : (($a->location->line ?? 0) <=> ($b->location->line ?? 0)));
    }

    private static function byRuleSeverityFileLine(Finding $a, Finding $b): int
    {
        $comparison = $a->ruleName <=> $b->ruleName;

        return $comparison !== 0 ? $comparison : self::bySeverityFileLine($a, $b);
    }

    private static function byClassSeverityLine(Finding $a, Finding $b): int
    {
        $comparison = self::extractClassName($a) <=> self::extractClassName($b);

        return $comparison !== 0 ? $comparison : self::bySeverityFileLine($a, $b);
    }

    private static function byNamespaceSeverityLine(Finding $a, Finding $b): int
    {
        $comparison = self::extractNamespaceName($a) <=> self::extractNamespaceName($b);

        return $comparison !== 0 ? $comparison : self::bySeverityFileLine($a, $b);
    }

    /**
     * Extracts the FQCN for grouping. Falls back to file path if no class context.
     */
    private static function extractClassName(Finding $finding): string
    {
        $sp = $finding->symbolPath;
        $ns = $sp->namespace ?? '';
        $type = $sp->type;

        if ($type !== null) {
            return $ns !== '' ? $ns . '\\' . $type : $type;
        }

        // File-level finding without class context — group under file path
        return $finding->location->pathString();
    }

    /**
     * Extracts the namespace for grouping. Uses the canonical global namespace label when no namespace is named.
     */
    private static function extractNamespaceName(Finding $finding): string
    {
        $ns = $finding->symbolPath->namespace ?? '';

        return \Qualimetrix\Core\Symbol\SymbolPath::forNamespace($ns)->toString();
    }

    private static function severityOrder(Severity $severity): int
    {
        return match ($severity) {
            Severity::Error => 0,
            Severity::Warning => 1,
            Severity::Info => 2,
        };
    }
}
