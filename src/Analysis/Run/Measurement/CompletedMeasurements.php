<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Measurement;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluationSummary;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;

/** The aggregation and formula outcomes of one completed measurement phase. */
final readonly class CompletedMeasurements
{
    public function __construct(
        public NamespaceTree $namespaceTree,
        public ComputedMetricEvaluationSummary $computedMetricEvaluation,
    ) {}
}
