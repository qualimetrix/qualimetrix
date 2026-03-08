<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Aggregator;

use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Profiler\ProfilerHolder;

/**
 * Aggregates metrics from lower levels (Method, File) to higher levels (Class, Namespace, Project).
 *
 * Uses MetricDefinitions to determine which aggregation strategies to apply.
 * No hardcoded metric names — fully generic.
 */
final class MetricAggregator
{
    /**
     * @param list<MetricDefinition> $definitions
     */
    public function __construct(private readonly array $definitions) {}

    /**
     * Aggregates metrics and stores results in the repository.
     */
    public function aggregate(InMemoryMetricRepository $repository): void
    {
        if ($this->definitions === []) {
            return;
        }

        $profiler = ProfilerHolder::get();

        $phases = [
            'aggregation.methods_to_classes' => new MethodToClassAggregator(),
            'aggregation.to_namespaces' => new ClassToNamespaceAggregator(),
            'aggregation.to_project' => new NamespaceToProjectAggregator(),
        ];

        foreach ($phases as $spanName => $phase) {
            $profiler->start($spanName, 'aggregation');
            $phase->aggregate($repository, $this->definitions);
            $profiler->stop($spanName);
        }
    }
}
