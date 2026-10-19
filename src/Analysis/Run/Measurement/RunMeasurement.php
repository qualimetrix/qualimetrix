<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Measurement;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluatorInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MeasurementAggregationInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;

/**
 * Starts a run's measurement repository and completes aggregation before formulas.
 *
 * @qmx-threshold health.cohesion 40 -- Repository creation and completed graph measurement share one run lifecycle; their independent injected participants do not form a shared instance-field graph.
 */
final readonly class RunMeasurement
{
    public function __construct(
        private MetricRepositoryFactoryInterface $repositoryFactory,
        private MeasurementAggregationInterface $measurementAggregation,
        private ComputedMetricEvaluatorInterface $computedMetricEvaluation,
    ) {}

    public function startRepository(): MetricRepositoryInterface
    {
        return $this->repositoryFactory->create();
    }

    public function complete(MetricRepositoryInterface $repository, DependencyGraphInterface $graph, int $filesAnalyzed): CompletedMeasurements
    {
        $tree = $this->measurementAggregation->aggregate($repository, $graph);
        $evaluation = $this->computedMetricEvaluation->evaluate($repository, $filesAnalyzed);
        return new CompletedMeasurements($tree, $evaluation);
    }
}
