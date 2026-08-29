<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

/**
 * Formats a bounded, deterministic sample of FQNs for a diagnostic message
 * that lists examples out of a set larger than a message should print whole.
 *
 * Shared by {@see DeclaredLayerReachability::coverage()} and
 * {@see UnassignedClassSummary::unassignedClasses()}: both build a
 * `sprintf`-style recommendation naming a few offending classes, and both are
 * gated separately (one by {@see \Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode},
 * the other by {@see UnassignedClassMode}), so neither owns the formatting for
 * the other.
 *
 * Sorted before slicing so CI diffs stay stable: `metrics->all()` iteration
 * order is not stable under parallel collection.
 *
 * @internal Consumed by {@see DeclaredLayerReachability} and {@see UnassignedClassSummary}.
 */
final class DiagnosticSampleList
{
    private const int LIMIT = 10;

    /**
     * @param list<string> $fqns
     */
    public static function format(array $fqns): ?string
    {
        if ($fqns === []) {
            return null;
        }

        sort($fqns);
        $sample = \array_slice($fqns, 0, self::LIMIT);
        $remaining = \count($fqns) - \count($sample);

        $list = implode(', ', $sample);

        return $remaining > 0 ? $list . \sprintf(' ...and %d more', $remaining) : $list;
    }
}
