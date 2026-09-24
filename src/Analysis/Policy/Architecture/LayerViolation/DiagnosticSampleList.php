<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

/**
 * Formats a bounded, deterministic sample of FQNs for a diagnostic message
 * that lists examples out of a set larger than a message should print whole.
 *
 * Shared by {@see DeclaredLayerReachability::coverage()},
 * {@see DoubtedAssignmentDiagnostic::forDoubts()} and
 * {@see UnassignedClassSummary::unassignedClasses()}: each builds a
 * `sprintf`-style text naming a few classes out of a larger set, and each is
 * gated separately (by {@see \Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode}
 * or by {@see UnassignedClassMode}), so none owns the formatting for the
 * others.
 *
 * Sorted before slicing so CI diffs stay stable: `metrics->all()` iteration
 * order is not stable under parallel collection.
 *
 * @internal Consumed by {@see DeclaredLayerReachability}, {@see DoubtedAssignmentDiagnostic} and
 *           {@see UnassignedClassSummary}.
 */
final class DiagnosticSampleList
{
    /** Public because a text that samples has to say how far the sample reaches. */
    public const int LIMIT = 10;

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
