<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Support;

use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Violation\Violation;

/**
 * Test helper: normalises the architecture-rule violation set down to a
 * stable {@code {rule, severity, source, target, type}} tuple shape so
 * cosmetic message tweaks don't churn the golden snapshots.
 *
 * Shared by integration tests that compare against pinned JSON files
 * (e.g. {@code LayerViolationIntegrationTest::goldenFileMatchesFullPolicyOutput},
 * {@code Phase1ConfigCompatibilityTest::phase1ShapeYamlLoadsAndProducesPinnedViolationSet}).
 * The projection deliberately strips line numbers and free-text messages —
 * those are exercised by message-shape tests separately.
 *
 * `dependencyType` is declared nullable on {@see Violation} (coverage
 * diagnostics and other architecture rows leave the edge unset). The
 * `instanceof` guard narrows the union to a concrete enum before reading
 * `->value`, satisfying both the projection's null-safety and
 * phpstan-strict's `nullsafe.neverNull` rule.
 */
final class ArchitectureViolationProjector
{
    /**
     * @param list<Violation> $violations
     *
     * @return list<array{rule: string, severity: string, source: string, target: string, type: string}>
     */
    public static function project(array $violations): array
    {
        $rows = [];
        foreach ($violations as $violation) {
            if (!str_starts_with($violation->ruleName, 'architecture.')) {
                continue;
            }
            $rows[] = [
                'rule' => $violation->ruleName,
                'severity' => $violation->severity->value,
                'source' => $violation->symbolPath->toString(),
                'target' => $violation->dependencyTarget?->toString() ?? '',
                'type' => $violation->dependencyType instanceof DependencyType ? $violation->dependencyType->value : '',
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
