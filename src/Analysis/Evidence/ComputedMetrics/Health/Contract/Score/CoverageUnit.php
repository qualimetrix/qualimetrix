<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score;

/**
 * What a health input's `.count` counts, and what the whole of that population is.
 *
 * The denominator is a count of *symbols the run measured*, never the `.count`
 * of a neighbouring metric: a denominator that is itself a measurement shrinks
 * whenever the measurement fails, and a coverage ratio that hides its own gap
 * is worse than none. `size.symbol-class-count` and `size.symbol-method-count`
 * are written by {@see \Qualimetrix\Analysis\Evidence\Measurement\Aggregation\AggregationHelper::addSymbolCounts()}
 * from the symbol list itself.
 *
 * Measured against the seventeen-project corpus, 2026-09-15:
 * `coupling.cbo.count` equals `size.symbol-class-count` on all seventeen and
 * `complexity.ccn.count` and `maintainability.mi.count` equal
 * `size.symbol-method-count` on all seventeen. `cohesion.lcom.count` runs one
 * to two short of the class population on four of them — interfaces carry no
 * LCOM — which is a gap to publish, not a denominator to tune away.
 *
 * `DeclaringNamespaces` carries a gap of the same kind, and it is permanent by
 * construction. A namespace declaring nothing but bare enums has an abstractness
 * of its own that is undefined — a bare enum is deliberately outside that
 * denominator (ADR 0062) — so no distance is published for it, while the
 * population counts it because it declares a type. Measured on this repository,
 * 2026-09-23: 166 of 168, printed as 99%, the two being
 * `Qualimetrix\Analysis\Finding\Contract\Control` and
 * `Qualimetrix\Core\Observation`, one bare enum each.
 *
 * Narrowing the population to the namespaces the aggregate reached would print
 * 100% and would be the same defect the leaf-only denominator was: a
 * denominator that is the aggregate's own walk cannot show what that walk did
 * not reach. Two namespaces nothing can measure are exactly what this line is
 * for, so the gap is documented here and the denominator left alone.
 */
enum CoverageUnit: string
{
    case Classes = 'classes';
    case Callables = 'callables';
    case DeclaringNamespaces = 'namespaces declaring a type';

    /**
     * The project-bag metric holding the whole population.
     *
     * `DeclaringNamespaces` used to have none, and its denominator came from
     * `NamespaceTree::getLeaves()` — the very set the namespace-collected
     * aggregate walks. A namespace the walk drops was then missing from both
     * sides of the ratio, so a run that lost 39 of 166 namespaces still printed
     * 100%. Counting the population from the symbols instead makes that loss
     * the number this line exists to show.
     */
    public function populationMetric(): string
    {
        return match ($this) {
            // Written out rather than imported from MetricName: the enum is a
            // published contract value and the import made Measurement's key
            // catalog a dependency of every consumer that touches a score.
            self::Classes => 'size.symbol-class-count',
            self::Callables => 'size.symbol-method-count',
            self::DeclaringNamespaces => 'size.symbol-declaring-namespace-count',
        };
    }
}
