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
 */
enum CoverageUnit: string
{
    case Classes = 'classes';
    case Callables = 'callables';
    case LeafNamespaces = 'leaf namespaces';

    /**
     * The project-bag metric holding the whole population, where one exists.
     *
     * `LeafNamespaces` has none: the population a namespace-collected aggregate
     * is offered is `NamespaceTree::getLeaves()`, read from the tree by the
     * caller — see
     * {@see \Qualimetrix\Analysis\Evidence\Measurement\Aggregation\NamespaceToProjectAggregator}.
     */
    public function populationMetric(): ?string
    {
        return match ($this) {
            // Written out rather than imported from MetricName: the enum is a
            // published contract value and the import made Measurement's key
            // catalog a dependency of every consumer that touches a score.
            self::Classes => 'size.symbol-class-count',
            self::Callables => 'size.symbol-method-count',
            self::LeafNamespaces => null,
        };
    }
}
