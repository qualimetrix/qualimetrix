<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Support;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\Finding;

/**
 * Test helper: normalises the architecture-rule finding set down to a
 * stable {@code {rule, severity, source, target, type}} tuple shape so
 * cosmetic message tweaks don't churn the golden snapshots.
 *
 * Shared by integration tests that compare against pinned JSON files
 * (e.g. {@code LayerViolationIntegrationTest::goldenFileMatchesFullPolicyOutput},
 * {@code Phase1ConfigCompatibilityTest::phase1ShapeYamlLoadsAndProducesPinnedFindingSet}).
 * The projection deliberately strips line numbers and free-text messages —
 * those are exercised by message-shape tests separately.
 *
 * `dependencyType` is declared nullable on {@see Finding} (coverage
 * diagnostics and other architecture rows leave the edge unset). The
 * `instanceof` guard narrows the union to a concrete enum before reading
 * `->value`, satisfying both the projection's null-safety and
 * phpstan-strict's `nullsafe.neverNull` rule.
 */
final class ArchitectureViolationProjector
{
    /**
     * @param list<Finding> $findings
     *
     * @return list<array{rule: string, severity: string, source: string, target: string, type: string}>
     */
    public static function project(array $findings): array
    {
        $rows = [];
        foreach ($findings as $finding) {
            if (!str_starts_with($finding->ruleName, 'architecture.')) {
                continue;
            }
            $rows[] = [
                'rule' => $finding->ruleName,
                'severity' => $finding->severity->value,
                'source' => $finding->symbolPath->toString(),
                'target' => $finding->dependencyTarget?->toString() ?? '',
                'type' => $finding->dependencyType instanceof DependencyType ? $finding->dependencyType->value : '',
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            $cmp = strcmp($a['rule'], $b['rule']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp($a['source'], $b['source']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp($a['target'], $b['target']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a['type'], $b['type']);
        });

        return $rows;
    }
}
