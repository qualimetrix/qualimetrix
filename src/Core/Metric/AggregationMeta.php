<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Metric;

/**
 * Constants for meta-counters injected during aggregation.
 *
 * Unlike MetricName constants (which correspond to collector-produced metrics),
 * these are synthetic counters added by the aggregation pipeline to track
 * symbol counts at namespace and project levels.
 */
final class AggregationMeta
{
    /**
     * Total number of methods and standalone functions in a namespace/project.
     *
     * Used as a denominator in health formulas (e.g., ccn__sum / symbolMethodCount).
     */
    public const string SYMBOL_METHOD_COUNT = 'symbolMethodCount';

    /**
     * Total number of classes, interfaces, traits, and enums in a namespace/project.
     */
    public const string SYMBOL_CLASS_COUNT = 'symbolClassCount';

    private function __construct() {}
}
