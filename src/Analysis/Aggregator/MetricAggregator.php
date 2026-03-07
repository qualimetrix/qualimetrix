<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Aggregator;

use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Metric\MetricCollectorInterface;
use AiMessDetector\Core\Profiler\ProfilerHolder;
use Traversable;

/**
 * Aggregates metrics from lower levels (Method, File) to higher levels (Class, Namespace, Project).
 *
 * Uses MetricDefinitions from collectors to determine which aggregation strategies to apply.
 * No hardcoded metric names — fully generic.
 */
final class MetricAggregator
{
    /** @var list<MetricCollectorInterface> */
    private readonly array $collectors;

    /**
     * @param iterable<MetricCollectorInterface> $collectors
     */
    public function __construct(iterable $collectors)
    {
        $this->collectors = $collectors instanceof Traversable
            ? iterator_to_array($collectors, false)
            : array_values($collectors);
    }

    /**
     * Aggregates metrics and stores results in the repository.
     */
    public function aggregate(InMemoryMetricRepository $repository): void
    {
        $profiler = ProfilerHolder::get();

        $profiler->start('aggregation.collect_definitions', 'aggregation');
        $definitions = AggregationHelper::collectDefinitions($this->collectors);
        $profiler->stop('aggregation.collect_definitions');

        if ($definitions === []) {
            return;
        }

        $phases = [
            'aggregation.methods_to_classes' => new MethodToClassAggregator(),
            'aggregation.to_namespaces' => new ClassToNamespaceAggregator(),
            'aggregation.to_project' => new NamespaceToProjectAggregator(),
        ];

        foreach ($phases as $spanName => $phase) {
            $profiler->start($spanName, 'aggregation');
            $phase->aggregate($repository, $definitions);
            $profiler->stop($spanName);
        }
    }
}
